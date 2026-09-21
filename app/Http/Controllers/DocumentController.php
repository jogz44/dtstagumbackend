<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDocumentRequest;
use App\Models\Division;
use App\Models\Document;
use App\Models\DocumentAcknowledgement;
use App\Models\DocumentAttachment;
use App\Models\DocumentHistory;
use App\Models\Office;
use App\Models\ScanPage;
use App\Models\ScanSession;
use App\Models\User;
use App\Services\AuditService;
use App\Services\DocumentAttentionService;
use App\Services\DocumentNotificationService;
use App\Services\DocumentTrackingService;
use App\Services\UserAuditAccess;
use App\Support\RoleNames;
use Illuminate\Support\Carbon;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DocumentController extends Controller
{
    public function index(Request $request, Document $documentModel, DocumentAttentionService $attentionService): JsonResponse
    {
        Document::autoArchiveCompleted();

        $tab = $request->query('tab', 'my');
        $user = $request->user();
        $query = $request->query('attention') === 'due'
            ? $attentionService->buildAuthorizedDueQuery($user)
            : Document::query();

        $query->with([
            'creator',
            'creator.office',
            'attachments',
            'acknowledgements' => fn ($query) => $query->latest(),
            'acknowledgements.user:id,first_name,last_name,username,is_active,position,office_id',
            'acknowledgements.user.office',
        ]);

        $searchParam = $request->query('search') ?? $request->query('q');
        $scopeParam = $request->query('scope') ?? $request->query('filter');
        $isTransmittedOrReceived = in_array($scopeParam, ['transmitted_or_received', 'office_history', 'office_activity', 'received'], true)
            || in_array($tab, ['transmitted_or_received', 'office_history', 'office_activity'], true);

        if ($isTransmittedOrReceived) {
            $query->where(function ($qConf) use ($user) {
                $canSeeConfidential = $user->can('documents.view_confidential')
                    || $user->hasAnyRole(RoleNames::adminRoles())
                    || $user->username === 'admin';
                if (! $canSeeConfidential) {
                    $qConf->where('is_confidential', false)
                        ->orWhere('created_by', $user->id)
                        ->orWhere('assigned_user', $user->full_name)
                        ->orWhere('assigned_user', (string) $user->id);
                }
            })->where('status', '!=', 'archived');

            $query->forReceivedOrTransmitted($user);

            if (! empty($searchParam)) {
                $searchTrimmed = trim($searchParam);
                $searchLower = strtolower($searchTrimmed);
                $terminalStatuses = ['approved', 'archived', 'completed', 'released'];
                $todayStr = Carbon::today('Asia/Manila')->toDateString();

                $monthMap = [
                    'jan' => 1, 'january' => 1,
                    'feb' => 2, 'february' => 2,
                    'mar' => 3, 'march' => 3,
                    'apr' => 4, 'april' => 4,
                    'may' => 5,
                    'jun' => 6, 'june' => 6,
                    'jul' => 7, 'july' => 7,
                    'aug' => 8, 'august' => 8,
                    'sep' => 9, 'sept' => 9, 'september' => 9,
                    'oct' => 10, 'october' => 10,
                    'nov' => 11, 'november' => 11,
                    'dec' => 12, 'december' => 12,
                ];
                $matchedMonth = $monthMap[$searchLower] ?? null;
                $dateCols = ['created_at', 'due_date', 'due_at', 'received_at', 'completed_at'];

                $query->where(function ($qSearch) use ($searchTrimmed, $searchLower, $terminalStatuses, $todayStr, $matchedMonth, $dateCols) {
                    $qSearch->where('tracking_number', 'LIKE', "%{$searchTrimmed}%")
                        ->orWhere('description', 'LIKE', "%{$searchTrimmed}%")
                        ->orWhere('origin_sender', 'LIKE', "%{$searchTrimmed}%")
                        ->orWhere('sender_name', 'LIKE', "%{$searchTrimmed}%")
                        ->orWhere('issued_by', 'LIKE', "%{$searchTrimmed}%")
                        ->orWhere('office_assignment', 'LIKE', "%{$searchTrimmed}%")
                        ->orWhere('division_assignment', 'LIKE', "%{$searchTrimmed}%")
                        ->orWhere('document_type', 'LIKE', "%{$searchTrimmed}%")
                        ->orWhere('assigned_user', 'LIKE', "%{$searchTrimmed}%")
                        ->orWhere('remarks', 'LIKE', "%{$searchTrimmed}%")
                        ->orWhere('status', 'LIKE', "%{$searchTrimmed}%");

                    foreach ($dateCols as $col) {
                        if ($matchedMonth) {
                            $qSearch->orWhereRaw("MONTH({$col}) = ?", [$matchedMonth]);
                        }
                        $qSearch->orWhereRaw("DATE_FORMAT({$col}, '%b') LIKE ?", ["%{$searchTrimmed}%"])
                            ->orWhereRaw("DATE_FORMAT({$col}, '%M') LIKE ?", ["%{$searchTrimmed}%"])
                            ->orWhereRaw("DATE_FORMAT({$col}, '%Y-%m-%d') LIKE ?", ["%{$searchTrimmed}%"])
                            ->orWhereRaw("DATE_FORMAT({$col}, '%m-%d') LIKE ?", ["%{$searchTrimmed}%"])
                            ->orWhereRaw("DATE_FORMAT({$col}, '%b %d, %Y') LIKE ?", ["%{$searchTrimmed}%"])
                            ->orWhereRaw("DATE_FORMAT({$col}, '%b %e, %Y') LIKE ?", ["%{$searchTrimmed}%"])
                            ->orWhereRaw("DATE_FORMAT({$col}, '%b %d') LIKE ?", ["%{$searchTrimmed}%"])
                            ->orWhereRaw("DATE_FORMAT({$col}, '%b %e') LIKE ?", ["%{$searchTrimmed}%"])
                            ->orWhereRaw("DATE_FORMAT({$col}, '%M %d, %Y') LIKE ?", ["%{$searchTrimmed}%"])
                            ->orWhereRaw("DATE_FORMAT({$col}, '%M %e, %Y') LIKE ?", ["%{$searchTrimmed}%"])
                            ->orWhereRaw("DATE_FORMAT({$col}, '%M %d') LIKE ?", ["%{$searchTrimmed}%"])
                            ->orWhereRaw("DATE_FORMAT({$col}, '%M %e') LIKE ?", ["%{$searchTrimmed}%"])
                            ->orWhereRaw("DATE_FORMAT({$col}, '%Y') LIKE ?", ["%{$searchTrimmed}%"]);
                    }

                    if ($searchLower === 'overdue') {
                        $qSearch->orWhere(function ($qOvd) use ($terminalStatuses, $todayStr) {
                            $qOvd->whereNotIn(DB::raw('LOWER(status)'), $terminalStatuses)
                                ->whereNotNull('due_date')
                                ->where('due_date', '<=', $todayStr);
                        });
                    } elseif ($searchLower === 'today') {
                        $qSearch->orWhereDate('created_at', $todayStr)
                            ->orWhereDate('received_at', $todayStr)
                            ->orWhereHas('acknowledgements', fn ($qAck) => $qAck->whereDate('created_at', $todayStr));
                    } elseif ($searchLower === 'pending') {
                        $qSearch->orWhereIn(DB::raw('LOWER(status)'), ['pending', 'registered', 'received', 'in_process', 'in progress', 'in_transit']);
                    } elseif ($searchLower === 'completed') {
                        $qSearch->orWhereIn(DB::raw('LOWER(status)'), ['completed', 'released', 'approved']);
                    }
                });
            }
        } elseif (! empty($searchParam)) {
            $searchTrimmed = trim($searchParam);
            $officeName = $user->office?->name;
            $officeNames = Document::getSimilarOfficeNames($officeName);

            $query = Document::query()->with([
                'creator',
                'creator.office',
                'attachments',
                'acknowledgements' => fn ($query) => $query->latest(),
                'acknowledgements.user:id,first_name,last_name,username,is_active,position,office_id',
                'acknowledgements.user.office',
            ])->where(function ($qConf) use ($user) {
                $canSeeConfidential = $user->can('documents.view_confidential')
                    || $user->hasAnyRole(RoleNames::adminRoles())
                    || $user->username === 'admin';
                if (! $canSeeConfidential) {
                    $qConf->where('is_confidential', false)
                        ->orWhere('created_by', $user->id)
                        ->orWhere('assigned_user', $user->full_name)
                        ->orWhere('assigned_user', (string) $user->id);
                }
            })
              ->where(function ($qUserScope) use ($user, $officeNames) {
                  $roleName = strtolower($user->roles->first()?->name ?? '');
                  $isElevatedUser = $user->username === 'admin'
                      || $user->hasAnyRole(RoleNames::adminRoles())
                      || $roleName === 'supervisor'
                      || $user->hasRole('supervisor');

                  if ($isElevatedUser) {
                      $qUserScope->where('created_by', $user->id)
                          ->orWhere('assigned_user', (string) $user->id)
                          ->orWhere('assigned_user', $user->full_name)
                          ->orWhere('assigned_user', $user->username)
                          ->orWhereHas('acknowledgements', function ($qAck) use ($user) {
                              $qAck->where('user_id', $user->id);
                          })
                          ->orWhereHas('histories', function ($qH) use ($user) {
                              $qH->where('performed_by', $user->id)
                                 ->orWhere('from_user_id', $user->id)
                                 ->orWhere('to_user_id', $user->id);
                          });

                      $qUserScope->orWhereHas('histories', function ($qH) use ($officeNames) {
                          if (! empty($officeNames)) {
                              $qH->whereIn('from_office', $officeNames)
                                 ->orWhereIn('to_office', $officeNames);
                          }
                      })
                      ->orWhereHas('creator', function ($qC) use ($user) {
                          if ($user->office_id) {
                              $qC->where('office_id', $user->office_id);
                          }
                      });

                      if (! empty($officeNames)) {
                          $qUserScope->orWhereIn('office_assignment', $officeNames)
                             ->orWhereIn('origin_sender', $officeNames);
                      }
                  } else {
                      // Regular receiver search scope: directly assigned (current holder), or created/involved (including transmitted documents)
                      $qUserScope->where(function ($qHolder) use ($user) {
                          $qHolder->where('assigned_user', (string) $user->id)
                              ->orWhere('assigned_user', $user->full_name)
                              ->orWhere('assigned_user', $user->username);
                      })->orWhere(function ($qInvolved) use ($user, $officeNames) {
                          $qInvolved->where(function ($qSub) use ($user) {
                              $qSub->where('created_by', $user->id)
                                  ->orWhereHas('acknowledgements', function ($qAck) use ($user) {
                                      $qAck->where('user_id', $user->id);
                                  })
                                  ->orWhereHas('histories', function ($qH) use ($user) {
                                      $qH->where('performed_by', $user->id)
                                         ->orWhere('from_user_id', $user->id)
                                         ->orWhere('to_user_id', $user->id);
                                  });
                          });

                          if (! empty($officeNames)) {
                              $qInvolved->orWhere(function ($qOff) use ($officeNames) {
                                  $qOff->whereNull('office_assignment')
                                       ->orWhere('office_assignment', '')
                                       ->orWhereIn('office_assignment', $officeNames);
                              });
                          }
                      });
                  }
              })
              ->where(function ($qSearch) use ($searchTrimmed) {
                  $searchLower = strtolower($searchTrimmed);
                  $terminalStatuses = ['approved', 'archived', 'completed', 'released'];
                  $todayStr = Carbon::today('Asia/Manila')->toDateString();

                  $monthMap = [
                      'jan' => 1, 'january' => 1,
                      'feb' => 2, 'february' => 2,
                      'mar' => 3, 'march' => 3,
                      'apr' => 4, 'april' => 4,
                      'may' => 5,
                      'jun' => 6, 'june' => 6,
                      'jul' => 7, 'july' => 7,
                      'aug' => 8, 'august' => 8,
                      'sep' => 9, 'sept' => 9, 'september' => 9,
                      'oct' => 10, 'october' => 10,
                      'nov' => 11, 'november' => 11,
                      'dec' => 12, 'december' => 12,
                  ];
                  $matchedMonth = $monthMap[$searchLower] ?? null;
                  $dateCols = ['created_at', 'due_date', 'due_at', 'received_at', 'completed_at'];

                  $qSearch->where('tracking_number', 'LIKE', "%{$searchTrimmed}%")
                      ->orWhere('description', 'LIKE', "%{$searchTrimmed}%")
                      ->orWhere('origin_sender', 'LIKE', "%{$searchTrimmed}%")
                      ->orWhere('sender_name', 'LIKE', "%{$searchTrimmed}%")
                      ->orWhere('issued_by', 'LIKE', "%{$searchTrimmed}%")
                      ->orWhere('office_assignment', 'LIKE', "%{$searchTrimmed}%")
                      ->orWhere('division_assignment', 'LIKE', "%{$searchTrimmed}%")
                      ->orWhere('document_type', 'LIKE', "%{$searchTrimmed}%")
                      ->orWhere('assigned_user', 'LIKE', "%{$searchTrimmed}%")
                      ->orWhere('remarks', 'LIKE', "%{$searchTrimmed}%")
                      ->orWhere('status', 'LIKE', "%{$searchTrimmed}%");

                  foreach ($dateCols as $col) {
                      if ($matchedMonth) {
                          $qSearch->orWhereRaw("MONTH({$col}) = ?", [$matchedMonth]);
                      }
                      $qSearch->orWhereRaw("DATE_FORMAT({$col}, '%b') LIKE ?", ["%{$searchTrimmed}%"])
                          ->orWhereRaw("DATE_FORMAT({$col}, '%M') LIKE ?", ["%{$searchTrimmed}%"])
                          ->orWhereRaw("DATE_FORMAT({$col}, '%Y-%m-%d') LIKE ?", ["%{$searchTrimmed}%"])
                          ->orWhereRaw("DATE_FORMAT({$col}, '%m-%d') LIKE ?", ["%{$searchTrimmed}%"])
                          ->orWhereRaw("DATE_FORMAT({$col}, '%b %d, %Y') LIKE ?", ["%{$searchTrimmed}%"])
                          ->orWhereRaw("DATE_FORMAT({$col}, '%b %e, %Y') LIKE ?", ["%{$searchTrimmed}%"])
                          ->orWhereRaw("DATE_FORMAT({$col}, '%b %d') LIKE ?", ["%{$searchTrimmed}%"])
                          ->orWhereRaw("DATE_FORMAT({$col}, '%b %e') LIKE ?", ["%{$searchTrimmed}%"])
                          ->orWhereRaw("DATE_FORMAT({$col}, '%M %d, %Y') LIKE ?", ["%{$searchTrimmed}%"])
                          ->orWhereRaw("DATE_FORMAT({$col}, '%M %e, %Y') LIKE ?", ["%{$searchTrimmed}%"])
                          ->orWhereRaw("DATE_FORMAT({$col}, '%M %d') LIKE ?", ["%{$searchTrimmed}%"])
                          ->orWhereRaw("DATE_FORMAT({$col}, '%M %e') LIKE ?", ["%{$searchTrimmed}%"])
                          ->orWhereRaw("DATE_FORMAT({$col}, '%Y') LIKE ?", ["%{$searchTrimmed}%"]);
                  }

                  if ($searchLower === 'overdue') {
                      $qSearch->orWhere(function ($qOvd) use ($terminalStatuses, $todayStr) {
                          $qOvd->whereNotIn(DB::raw('LOWER(status)'), $terminalStatuses)
                              ->whereNotNull('due_date')
                              ->where('due_date', '<=', $todayStr);
                      });
                  } elseif ($searchLower === 'today') {
                      $qSearch->orWhereDate('created_at', $todayStr)
                          ->orWhereDate('received_at', $todayStr)
                          ->orWhereHas('acknowledgements', fn ($qAck) => $qAck->whereDate('created_at', $todayStr));
                  } elseif ($searchLower === 'pending') {
                      $qSearch->orWhereIn(DB::raw('LOWER(status)'), ['pending', 'registered', 'received', 'in_process', 'in progress', 'in_transit']);
                  } elseif ($searchLower === 'completed') {
                      $qSearch->orWhereIn(DB::raw('LOWER(status)'), ['completed', 'released', 'approved']);
                  }
              });
        } elseif ($request->query('attention') === 'due') {
            // The canonical authorized due scope replaces tab-specific scopes.
        } elseif ($tab === 'my') {
            $query->forMyDocuments($user);
        } elseif ($tab === 'registry') {
            $query->forRegistry($user);
        } elseif ($tab === 'incoming') {
            $query->forIncoming($user);
        } elseif ($tab === 'outgoing') {
            $query->forOutgoing($user);
        } elseif ($tab === 'archived') {
            $query->where('status', 'archived')
                ->where(function ($q) use ($user) {
                    $q->where('created_by', $user->id)
                        ->orWhere('office_assignment', $user->office?->name)
                        ->orWhereHas('creator', function ($q2) use ($user) {
                            $q2->where('office_id', $user->office_id);
                        });
                });
        } elseif ($tab === 'overdue') {
            $query->forIncoming($user)
                ->where('created_at', '<', now()->subHours(24));
        }

        if ($request->filled('office_assignment')) {
            $assignment = $request->query('office_assignment');
            if ($assignment === '__unassigned__' || $assignment === 'unassigned') {
                $query->where(function ($q) {
                    $q->whereNull('office_id')
                        ->orWhereNull('office_assignment')
                        ->orWhere('office_assignment', '')
                        ->orWhere('office_assignment', 'Unassigned Office');
                });
            } else {
                $query->where('office_assignment', $assignment);
            }
        } elseif ($request->filled('office_id')) {
            $officeId = $request->query('office_id');
            if ($officeId === 'unassigned' || $officeId === '__unassigned__') {
                $query->where(function ($q) {
                    $q->whereNull('office_id')
                        ->orWhereNull('office_assignment')
                        ->orWhere('office_assignment', '')
                        ->orWhere('office_assignment', 'Unassigned Office');
                });
            } else {
                $office = Office::find($officeId);
                if ($office) {
                    $query->where(function ($q) use ($officeId, $office) {
                        $q->where('office_id', $officeId)
                            ->orWhere('office_assignment', $office->name);
                    });
                } else {
                    $query->where('office_id', $officeId);
                }
            }
        }

        if ($request->filled('division_assignment')) {
            $assignment = $request->query('division_assignment');
            if ($assignment === '__unassigned__' || $assignment === 'unassigned') {
                $query->where(function ($q) {
                    $q->whereNull('division_id')
                        ->orWhereNull('division_assignment')
                        ->orWhere('division_assignment', '')
                        ->orWhere('division_assignment', 'Unassigned Division');
                });
            } else {
                $query->where('division_assignment', $assignment);
            }
        } elseif ($request->filled('division_id')) {
            $divisionId = $request->query('division_id');
            if ($divisionId === 'unassigned' || $divisionId === '__unassigned__') {
                $query->where(function ($q) {
                    $q->whereNull('division_id')
                        ->orWhereNull('division_assignment')
                        ->orWhere('division_assignment', '')
                        ->orWhere('division_assignment', 'Unassigned Division');
                });
            } else {
                $division = Division::find($divisionId);
                if ($division) {
                    $query->where(function ($q) use ($divisionId, $division) {
                        $q->where('division_id', $divisionId)
                            ->orWhere('division_assignment', $division->name);
                    });
                } else {
                    $query->where('division_id', $divisionId);
                }
            }
        }

        $documents = $query->latest()->get();
        $assignedUserIds = $documents
            ->pluck('assigned_user')
            ->filter(fn ($assignedUser) => is_string($assignedUser) && Str::isUuid($assignedUser))
            ->unique()
            ->values();
        $assignedUsers = User::query()
            ->whereKey($assignedUserIds)
            ->get()
            ->keyBy('id');

        $documents->each(function ($doc) use ($assignedUsers) {
            $assigned = $doc->assigned_user;
            if (! empty($assigned) && Str::isUuid($assigned)) {
                $user = $assignedUsers->get($assigned);
                $doc->assigned_user = $user ? $user->full_name : $assigned;
            }
        });

        return response()->json([
            'data' => $documents,
        ]);
    }

    public function show(Request $request, Document $document, DocumentTrackingService $trackingService): JsonResponse
    {
        $this->authorizeDocumentRead($request, $document, $trackingService);

        $document->load([
            'creator',
            'creator.office',
            'attachments',
            'acknowledgements' => fn ($query) => $query->latest(),
            'acknowledgements.user:id,first_name,last_name,username,is_active,position,office_id',
            'acknowledgements.user.office',
        ]);

        $assigned = $document->assigned_user;
        if (! empty($assigned) && Str::isUuid($assigned)) {
            $user = User::find($assigned);
            $document->assigned_user = $user ? $user->full_name : $assigned;
        }

        return response()->json([
            'data' => $document,
        ]);
    }

    public function timeline(Request $request, Document $document, UserAuditAccess $access): JsonResponse
    {
        $viewer = $request->user();

        $this->authorizeTimelineRead($viewer, $document, $access);

        $document->load([
            'acknowledgements' => fn ($query) => $query->latest(),
            'acknowledgements.user:id,first_name,last_name,username,is_active',
        ]);

        $events = DocumentHistory::query()
            ->where('document_id', $document->id)
            ->with([
                'user:id,first_name,last_name,username,position,is_active,office_id',
                'user.office:id,name,short_name',
                'fromUser:id,first_name,last_name,username,position,is_active,office_id',
                'fromUser.office:id,name,short_name',
                'toUser:id,first_name,last_name,username,position,is_active,office_id',
                'toUser.office:id,name,short_name',
            ])
            ->oldest('created_at')
            ->orderBy('id')
            ->get();

        $viewerCanSeeConfidential = $viewer->can('documents.view_confidential') || $viewer->can('can_view_confidential');
        $canShowSensitiveFields = ! $document->is_confidential || $viewerCanSeeConfidential;

        return response()->json([
            'document' => [
                'id' => $document->id,
                'tracking_number' => $document->tracking_number,
                'particular' => $canShowSensitiveFields ? $document->description : null,
                'type' => $document->document_type,
                'priority' => $document->priority_level,
                'status' => $document->status,
                'current_holder' => $canShowSensitiveFields ? $this->currentHolderPayload($document) : null,
                'is_confidential' => $document->is_confidential,
                'is_redacted' => ! $canShowSensitiveFields,
            ],
            'data' => $events
                ->map(fn (DocumentHistory $event) => $this->timelineEventPayload($event, $canShowSensitiveFields))
                ->values(),
        ]);
    }

    public function store(StoreDocumentRequest $request, AuditService $audit): JsonResponse
    {
        $validated = $request->safe()->except('attachments');

        $document = DB::transaction(function () use ($request, $validated) {
            $user = $request->user();

            if ($validated['send_to_all'] ?? false) {
                $validated['is_internal'] = true;
            }

            if ($validated['is_internal'] ?? false) {
                // Only default origin_sender to the registrar's office if none was explicitly provided.
                // Do NOT overwrite it — the user may have intentionally typed a different origin.
                if (empty($validated['origin_sender'])) {
                    $validated['origin_sender'] = $user->office?->name ?? $user->full_name;
                }
            }

            // Registration is automatically self-assigned to the creator in their office and division
            $validated['self_assign'] = true;
            $validated['is_internal'] = true;
            $validated['office_assignment'] = $user->office?->name;
            $validated['division_assignment'] = $user->division?->name;
            $validated['assigned_user'] = $request->input('assigned_user') ?: $user->full_name;
            $newStatus = 'pending';

            // Resolve target user if possible
            $targetUser = null;
            $assignedName = $validated['assigned_user'] ?? null;
            if ($assignedName && $assignedName !== '-Select user-' && $assignedName !== '-Select office first-') {
                // First try resolving by ID (like in release)
                $targetUser = User::find($assignedName);
                if ($targetUser) {
                    $assignedName = $targetUser->full_name;
                } else {
                    // Resolve by full name string
                    $targetUser = $this->resolveAssignedUser($assignedName);
                }
            }

            $document = Document::create([
                ...$validated,
                'tracking_number' => $request->input('tracking_number') ?: ('DOC-'.Str::upper(Str::random(12))),
                'created_by' => $user->id,
                'assigned_user' => $assignedName,
                'status' => $newStatus,
            ]);

            $disk = config('filesystems.document_disk', 'shared_documents');

            foreach ($request->file('attachments', []) as $file) {
                if (! $file || ! $file->isValid()) {
                    continue;
                }
                $path = $file->store("documents/{$document->id}", $disk);
                if (! $path) {
                    throw new \RuntimeException("Failed to store attachment file '{$file->getClientOriginalName()}' on server storage.");
                }

                $document->attachments()->create([
                    'disk' => $disk,
                    'path' => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
                    'size' => $file->getSize(),
                ]);
            }

            // Sync phone-scanned pages if a session is provided
            if ($request->filled('scan_session_token')) {
                $scanPages = ScanPage::where('session_token', $request->scan_session_token)
                    ->orderBy('page_number')
                    ->get();

                $defaultDisk = config('filesystems.default', 'local');

                foreach ($scanPages as $page) {
                    if (Storage::disk($defaultDisk)->exists($page->file_path)) {
                        $originalName = basename($page->file_path);
                        $mimeType = Storage::disk($defaultDisk)->mimeType($page->file_path) ?? 'image/png';
                        $size = Storage::disk($defaultDisk)->size($page->file_path);

                        // Copy from temp scanner directory to permanent document disk location
                        $newPath = "documents/{$document->id}/{$originalName}";
                        Storage::disk($disk)->writeStream(
                            $newPath,
                            Storage::disk($defaultDisk)->readStream($page->file_path)
                        );

                        $document->attachments()->create([
                            'disk' => $disk,
                            'path' => $newPath,
                            'original_name' => "scanned_page_{$page->page_number}.png",
                            'mime_type' => $mimeType,
                            'size' => $size,
                            'type' => 'document',
                        ]);
                    }
                }

                // Cleanup temporary storage and session
                Storage::disk($defaultDisk)->deleteDirectory("scan-sessions/{$request->scan_session_token}");
                ScanSession::where('token', $request->scan_session_token)->delete();
            }

            // 1. Log in canonical document transaction history.
            $historyType = ($newStatus === 'released') ? DocumentHistory::RELEASED : DocumentHistory::REGISTERED;
            $historyRemarks = $document->remarks ?: null;

            DocumentHistory::record($document, $historyType, $request->user(), [
                'to_user_id' => $targetUser?->id,
                'to_division_id' => $targetUser?->division_id,
                'remarks' => $historyRemarks,
                'from_office' => $document->origin_sender ?? $user->office?->name,
                'to_office' => $document->office_assignment,
                'status_after' => $document->status,
            ]);

            // If sent to all and internal:
            if ($document->send_to_all && $document->is_internal) {
                DocumentHistory::record($document, 'shared', $request->user(), [
                    'remarks' => null,
                    'from_office' => $user->office?->name,
                    'to_office' => $user->office?->name,
                    'status_before' => $document->status,
                    'status_after' => $document->status,
                ]);
            }

            // 3. Create pending acknowledgement if a user is assigned
            if ($assignedName && $assignedName !== '-Select user-' && $assignedName !== '-Select office first-') {
                $acknowledgement = $document->acknowledgements()->create([
                    'user_id' => $targetUser?->id,
                    'assigned_name' => $assignedName,
                    'from_office' => $document->origin_sender ?? $user->office?->name,
                    'remarks' => null,
                ]);

                if ($newStatus !== 'released') {
                    DocumentHistory::record($document, DocumentHistory::ASSIGNED, $request->user(), [
                        'to_user_id' => $targetUser?->id,
                        'to_division_id' => $targetUser?->division_id,
                        // No remark on first assignment — only re-assignment shows a remark
                        'remarks' => null,
                        'from_office' => $document->origin_sender ?? $user->office?->name,
                        'to_office' => $document->office_assignment,
                        'status_before' => $document->status,
                        'status_after' => $document->status,
                        'metadata' => [
                            'assigned_name' => $assignedName,
                        ],
                    ]);
                }

                if ($targetUser) {
                    app(DocumentNotificationService::class)->notifyReceived(
                        $document,
                        $targetUser,
                        $request->user(),
                        $acknowledgement->id,
                    );
                }
            }

            return $document;
        });

        $audit->activity(
            $request->user(),
            'document.received',
            'Documents',
            'Uploaded a new document.',
            $request,
            [
                'resource_type' => 'document',
                'resource_id' => $document->id,
            ],
        );

        return response()->json([
            'message' => 'Document saved successfully.',
            'document' => $document->load('attachments'),
        ], 201);
    }

    private function authorizeTimelineRead(User $authUser, Document $document, UserAuditAccess $access): void
    {
        if ($authUser->hasRole(RoleNames::ADMIN)) {
            return;
        }

        $isInvolved = DocumentHistory::query()
            ->where('document_id', $document->id)
            ->involvingUser($authUser)
            ->exists();

        if ($isInvolved) {
            return;
        }

        $involvedUserIds = DocumentHistory::query()
            ->where('document_id', $document->id)
            ->get(['performed_by', 'from_user_id', 'to_user_id'])
            ->flatMap(fn (DocumentHistory $history) => [
                $history->performed_by,
                $history->from_user_id,
                $history->to_user_id,
            ])
            ->filter()
            ->unique()
            ->values();

        $canAuditAnInvolvedUser = User::query()
            ->whereIn('id', $involvedUserIds)
            ->get(['id', 'office_id', 'division_id'])
            ->contains(fn (User $targetUser) => $access->canRead($authUser, $targetUser));

        if ($canAuditAnInvolvedUser) {
            return;
        }

        abort(403, 'You are not authorized to view this document timeline.');
    }

    private function timelineEventPayload(DocumentHistory $event, bool $canShowSensitiveFields): array
    {
        $metadata = $event->metadata ?? [];

        return [
            'event_id' => $event->id,
            'action' => $event->action,
            'actor' => $canShowSensitiveFields ? $this->userPayload($event->user) : null,
            'from_user' => $canShowSensitiveFields ? $this->userPayload($event->fromUser, $metadata['from_user_name'] ?? null) : null,
            'to_user' => $canShowSensitiveFields ? $this->userPayload($event->toUser, $metadata['assigned_name'] ?? $metadata['to_user_name'] ?? null) : null,
            'from_office' => $event->from_office,
            'to_office' => $event->to_office,
            'status_before' => $event->status_before,
            'status_after' => $event->status_after,
            'remarks' => $canShowSensitiveFields ? $event->remarks : null,
            'performed_at' => $event->created_at?->toIso8601String(),
        ];
    }

    private function userPayload(?User $user, ?string $fallbackName = null): ?array
    {
        $name = $user?->full_name ?: $user?->username ?: $fallbackName;

        if (! $name) {
            return null;
        }

        $officeName = $user?->relationLoaded('office') && $user->office ? ($user->office->name ?? $user->office->short_name) : null;
        $position = $user?->position;

        return [
            'id' => $user?->id,
            'name' => $name,
            'office' => $officeName,
            'position' => $position,
            'is_active' => $user?->is_active,
        ];
    }

    private function currentHolderPayload(Document $document): ?array
    {
        $acknowledgement = $document->acknowledgements->first();
        $holderUser = $acknowledgement?->user;
        $holderName = $holderUser?->full_name
            ?: $acknowledgement?->assigned_name
            ?: $document->assigned_user;

        if (! $holderName) {
            return null;
        }

        return [
            'id' => $holderUser?->id,
            'name' => $holderName,
            'is_active' => $holderUser?->is_active,
        ];
    }

    private function resolveAssignedUser(string $assignedName): ?User
    {
        $normalizedAssignedName = $this->normalizeName($assignedName);
        $terms = collect(preg_split('/\s+/', $assignedName, -1, PREG_SPLIT_NO_EMPTY))
            ->filter(fn (string $term) => strlen($term) > 1)
            ->values();

        $candidates = User::query()
            ->when($terms->isNotEmpty(), function ($query) use ($terms) {
                $query->where(function ($nameQuery) use ($terms) {
                    foreach ($terms as $term) {
                        $nameQuery
                            ->orWhere('first_name', 'LIKE', "%{$term}%")
                            ->orWhere('last_name', 'LIKE', "%{$term}%");
                    }
                });
            })
            ->limit(25)
            ->get();

        return $candidates->first(
            fn (User $user) => $this->normalizeName($user->full_name) === $normalizedAssignedName
        ) ?? $candidates->first();
    }

    private function normalizeName(string $name): string
    {
        return str($name)
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->lower()
            ->toString();
    }

    public function update(Request $request, Document $document): JsonResponse
    {
        $validated = $request->validate([
            'document_type' => 'nullable|string|max:255',
            'priority_level' => 'nullable|string|max:100',
            'description' => 'nullable|string|max:500',
            'is_confidential' => 'nullable|boolean',
            'remarks' => 'nullable|string',
            'due_date' => 'nullable|date',
        ]);

        $document->update($validated);

        $historyRemarks = 'Document details were updated.';
        if (! empty($validated['remarks'])) {
            $historyRemarks = $validated['remarks'];
        }

        DocumentHistory::record($document, 'edited', $request->user(), [
            'remarks' => $historyRemarks,
        ]);

        return response()->json([
            'message' => 'Document updated successfully.',
            'document' => $document->fresh(),
        ]);
    }

    public function transfer(Request $request, Document $document): JsonResponse
    {
        $validated = $request->validate([
            'office_assignment' => 'nullable|string',
            'division_assignment' => 'nullable|string',
            'assigned_user' => 'nullable|string',
            'remarks' => 'nullable|string',
        ]);

        $statusBefore = $document->status;
        $fromOffice = $document->office_assignment ?? $document->origin_sender;
        $currentUser = $request->user();

        $destOffice = $validated['office_assignment'] ?? $document->office_assignment;
        $destDivision = $validated['division_assignment'] ?? null;
        $assignedName = $validated['assigned_user'] ?? null;

        $targetUser = null;
        if ($assignedName && $assignedName !== '-Select user-' && $assignedName !== '-Select office first-') {
            $targetUser = User::find($assignedName);
            if ($targetUser) {
                $assignedName = $targetUser->full_name;
            } else {
                $targetUser = $this->resolveAssignedUser($assignedName);
            }
        }

        $document->update([
            'office_assignment' => $destOffice,
            'division_assignment' => $destDivision,
            'assigned_user' => $assignedName,
            'status' => 'transferred',
        ]);

        if ($targetUser) {
            $acknowledgement = $document->acknowledgements()->create([
                'user_id' => $targetUser->id,
                'assigned_name' => $targetUser->full_name,
                'from_office' => $fromOffice,
                'remarks' => null,
            ]);

            app(DocumentNotificationService::class)->notifyReceived(
                $document,
                $targetUser,
                $currentUser,
                $acknowledgement->id,
            );
        }

        $remarks = ! empty($validated['remarks']) ? $validated['remarks'] : null;

        DocumentHistory::record($document, DocumentHistory::TRANSFERRED, $currentUser, [
            'to_user_id' => $targetUser?->id,
            'to_division_id' => $targetUser?->division_id,
            'remarks' => $remarks,
            'from_office' => $fromOffice,
            'to_office' => $destOffice,
            'status_before' => $statusBefore,
            'status_after' => 'transferred',
            'metadata' => [
                'assigned_name' => $document->assigned_user,
                'to_office' => $destOffice,
            ],
        ]);

        return response()->json([
            'message' => 'Document transmitted successfully.',
            'document' => $document->fresh([
                'creator',
                'creator.office',
                'attachments',
                'acknowledgements' => fn ($q) => $q->latest(),
            ]),
        ]);
    }

    public function release(Request $request, Document $document): JsonResponse
    {
        $validated = $request->validate([
            'to_office' => 'nullable|string',
            'to_division' => 'nullable|string',
            'to_user' => 'nullable|string',
            'remarks' => 'nullable|string',
        ]);

        $statusBefore = $document->status;

        $targetUser = null;
        $assignedUser = null;
        if (! empty($validated['to_user'])) {
            $targetUser = User::find($validated['to_user']);
            if ($targetUser) {
                $assignedUser = $targetUser->full_name;
            }
        }

        $document->update([
            'status' => 'released',
            'office_assignment' => $validated['to_office'] ?? $document->office_assignment,
            'division_assignment' => $validated['to_division'] ?? null,
            'assigned_user' => $assignedUser ?? $validated['to_user'] ?? null,
        ]);

        if ($targetUser) {
            $acknowledgement = $document->acknowledgements()->create([
                'user_id' => $targetUser->id,
                'assigned_name' => $targetUser->full_name,
                'from_office' => $request->user()->office?->name,
                'remarks' => null,
            ]);

            app(DocumentNotificationService::class)->notifyReceived(
                $document,
                $targetUser,
                $request->user(),
                $acknowledgement->id,
            );
        }

        DocumentHistory::record($document, 'released', $request->user(), [
            'to_user_id' => $targetUser?->id,
            'to_division_id' => $targetUser?->division_id,
            'remarks' => $validated['remarks'] ?? null,
            'to_office' => $validated['to_office'] ?? null,
            'status_before' => $statusBefore,
            'status_after' => 'released',
            'metadata' => [
                'released_to' => $assignedUser ?? $validated['to_user'] ?? null,
            ],
        ]);

        return response()->json([
            'message' => 'Document released successfully.',
            'document' => $document->fresh(),
        ]);
    }

    public function archive(Request $request, Document $document): JsonResponse
    {
        if (strtolower((string) $document->status) !== 'completed') {
            return response()->json([
                'message' => 'Only completed documents can be archived.',
            ], 422);
        }

        $statusBefore = $document->status;

        $document->update([
            'status' => 'archived',
        ]);

        DocumentHistory::record($document, 'archived', $request->user(), [
            'remarks' => $request->input('remarks') ?: null,
            'status_before' => $statusBefore,
            'status_after' => 'archived',
        ]);

        return response()->json([
            'message' => 'Document archived successfully.',
            'document' => $document->fresh(),
        ]);
    }

    public function returnDocument(Request $request, Document $document): JsonResponse
    {
        $validated = $request->validate([
            'remarks' => 'nullable|string',
        ]);

        $statusBefore = $document->status;
        $fromOffice = $document->office_assignment;

        // Find the last user who assigned, transferred, or created this document before the current user
        $lastHistory = DocumentHistory::where('document_id', $document->id)
            ->whereIn('action', ['ASSIGNED', 'TRANSFERRED', 'FORWARDED', 'RELEASED', 'REGISTERED', 'RETURNED'])
            ->where('performed_by', '!=', $request->user()->id)
            ->latest()
            ->first();

        $lastUser = null;
        if ($lastHistory) {
            $lastUser = User::find($lastHistory->performed_by);
        }
        if (! $lastUser) {
            $lastUser = $document->creator;
        }

        $toOffice = $lastUser?->office?->name ?? $document->origin_sender;

        $document->update([
            'status' => 'returned',
            'office_assignment' => $toOffice,
            'division_assignment' => $lastUser?->division?->name,
            'assigned_user' => $lastUser ? $lastUser->full_name : null,
        ]);

        if ($lastUser) {
            $acknowledgement = $document->acknowledgements()->create([
                'user_id' => $lastUser->id,
                'assigned_name' => $lastUser->full_name,
                'from_office' => $request->user()->office?->name,
                'remarks' => null,
            ]);

            app(DocumentNotificationService::class)->notifyReceived(
                $document,
                $lastUser,
                $request->user(),
                $acknowledgement->id,
            );
        }

        DocumentHistory::record($document, DocumentHistory::RETURNED, $request->user(), [
            'to_user_id' => $lastUser?->id,
            'to_division_id' => $lastUser?->division_id,
            'remarks' => $validated['remarks'] ?? null,
            'from_office' => $fromOffice,
            'to_office' => $toOffice,
            'status_before' => $statusBefore,
            'status_after' => 'returned',
        ]);

        return response()->json([
            'message' => 'Document returned successfully.',
            'document' => $document->fresh(),
        ]);
    }

    public function complete(Request $request, Document $document): JsonResponse
    {
        $user = $request->user();

        // Case-insensitive role check (handles DB inconsistencies like 'Admin' vs 'admin')
        $userRoles = method_exists($user, 'getRoleNames')
            ? $user->getRoleNames()->map(fn($r) => strtolower($r))->toArray()
            : [];

        $hasExplicitPermission = (method_exists($user, 'can') && ($user->can('can_complete_document') || $user->can('can_edit_document')))
            || (method_exists($user, 'hasPermissionTo') && ($user->hasPermissionTo('can_complete_document') || $user->hasPermissionTo('can_edit_document')));

        $isPrivilegedRole = $hasExplicitPermission
            || in_array('admin', $userRoles)
            || in_array('supervisor', $userRoles)
            || in_array('receiver', $userRoles)
            || in_array('department_head', $userRoles)
            || in_array('office_head', $userRoles)
            || in_array('staff', $userRoles)
            || in_array('user', $userRoles);

        $userOfficeName = strtolower(trim($user->office?->name ?? ''));
        $userOfficeId = $user->office_id;
        $docOfficeAssignment = strtolower(trim($document->office_assignment ?? $document->office_name ?? $document->office ?? ''));
        $sameOffice = ($userOfficeName && $docOfficeAssignment && $userOfficeName === $docOfficeAssignment)
            || ($userOfficeId && $document->office_id && (int) $userOfficeId === (int) $document->office_id);

        $isHolder = ($document->assigned_user_id && $document->assigned_user_id === $user->id)
            || ($document->assigned_user && (
                strtolower(trim($document->assigned_user)) === strtolower(trim($user->full_name))
                || $document->assigned_user === (string) $user->id
            ))
            || $sameOffice;

        $isCreator = $document->created_by === $user->id;

        if (! $isPrivilegedRole && ! $isHolder && ! $isCreator) {
            return response()->json(['message' => 'This action is unauthorized.'], 403);
        }

        $validated = $request->validate([
            'remarks' => 'nullable|string',
        ]);

        $statusBefore = $document->status;
        $remarks = $validated['remarks'] ?? null;

        $document->update([
            'status' => 'completed',
            'completed_at' => now(),
            'completed_by_user_id' => $user->id,
            'remarks' => $remarks,
        ]);

        try {
            app(DocumentStatusService::class)->complete($document, $user, null, $remarks);
        } catch (\Throwable $e) {
            // Document status history already created or handled
        }

        DocumentHistory::record($document, DocumentHistory::COMPLETED, $user, [
            'remarks' => $remarks,
            'status_before' => $statusBefore,
            'status_after' => 'completed',
        ]);

        app(DocumentNotificationService::class)->notifyApproved($document, $user);

        return response()->json([
            'message' => 'Document marked as complete.',
            'document' => $document->fresh(),
        ]);
    }

    public function share(Request $request, Document $document): JsonResponse
    {
        $validated = $request->validate([
            'office' => 'required|string',
            'division' => 'nullable|string',
            'user' => 'nullable|string',
            'note' => 'nullable|string',
        ]);

        DocumentHistory::record($document, 'shared', $request->user(), [
            'remarks' => $validated['note'] ?? null,
            'to_office' => $validated['office'],
            'metadata' => [
                'to_division' => $validated['division'] ?? null,
                'shared_with_user' => $validated['user'] ?? null,
            ],
        ]);

        return response()->json([
            'message' => 'Document shared successfully.',
        ]);
    }

    public function showAttachment(
        Request $request,
        DocumentAttachment $attachment,
        DocumentTrackingService $trackingService,
    ) {
        $document = $attachment->document;
        abort_unless($document, 404, 'Document not found.');
        if ($request->user()) {
            $this->authorizeDocumentRead($request, $document, $trackingService);
        }

        $disk = $attachment->disk ?? 'shared_documents';
        /** @var FilesystemAdapter $storage */
        $storage = Storage::disk($disk);
        if (! $storage->exists($attachment->path)) {
            $fallbackDisks = ['shared_documents', 'public', 'local'];
            foreach ($fallbackDisks as $fDisk) {
                if ($fDisk !== $disk && Storage::disk($fDisk)->exists($attachment->path)) {
                    $storage = Storage::disk($fDisk);
                    break;
                }
            }
        }

        if (! $storage->exists($attachment->path)) {
            $fullPath = storage_path('app/' . $attachment->path);
            if (file_exists($fullPath)) {
                return response()->file($fullPath, [
                    'Content-Type' => $attachment->mime_type ?? 'application/octet-stream',
                    'Content-Disposition' => 'inline; filename="' . rawurlencode($attachment->original_name) . '"',
                ]);
            }
            abort(404, 'Attachment file not found on server storage disk.');
        }

        return $storage->response($attachment->path, $attachment->original_name, [
            'Content-Type' => $attachment->mime_type ?? 'application/octet-stream',
        ], 'inline');
    }

    public function retrieve(Request $request, Document $document): JsonResponse
    {
        $validated = $request->validate([
            'remarks' => 'nullable|string',
        ]);

        $statusBefore = $document->status;

        $document->update([
            'status' => 'registered',
            'completed_at' => null,
        ]);

        DocumentHistory::record($document, 'RETRIEVED', $request->user(), [
            'remarks' => isset($validated['remarks']) && trim($validated['remarks']) !== '' ? trim($validated['remarks']) : null,
            'status_before' => $statusBefore,
            'status_after' => 'registered',
        ]);

        return response()->json([
            'message' => 'Document retrieved successfully.',
            'document' => $document->fresh(),
        ]);
    }

    public function getMessages(Document $document): JsonResponse
    {
        $messages = $document->messages()
            ->with(['user:id,first_name,last_name,username,position,office_id', 'user.office'])
            ->oldest()
            ->get();

        return response()->json([
            'data' => $messages,
        ]);
    }

    public function sendMessage(Request $request, Document $document): JsonResponse
    {
        $validated = $request->validate([
            'message' => 'required|string',
            'type' => 'nullable|string',
        ]);

        $message = $document->messages()->create([
            'user_id' => $request->user()->id,
            'message' => $validated['message'],
            'type' => $validated['type'] ?? 'comment',
        ]);

        return response()->json([
            'message' => 'Message sent successfully.',
            'data' => $message->load(['user:id,first_name,last_name,username,position,office_id', 'user.office']),
        ], 201);
    }

    public function getDocumentTypes(): JsonResponse
    {
        $defaults = ['Venue', 'Memorandum'];

        return response()->json($defaults);
    }

    public function checkLibraryUsage(Request $request): JsonResponse
    {
        $type = trim((string) $request->query('type', ''));
        $priority = trim((string) $request->query('priority', ''));

        $typeCount = 0;
        if ($type !== '') {
            $typeCount = Document::whereRaw('LOWER(document_type) = ?', [strtolower($type)])->count();
        }

        $priorityCount = 0;
        if ($priority !== '') {
            $priorityCount = Document::whereRaw('LOWER(priority_level) = ?', [strtolower($priority)])->count();
        }

        return response()->json([
            'type_count' => $typeCount,
            'priority_count' => $priorityCount,
            'is_type_used' => $typeCount > 0,
            'is_priority_used' => $priorityCount > 0,
        ]);
    }

    /**
     * Reassign a document to another user within their office.
     * Allowed for: admins, supervisors, or the current holder of the document.
     */
    public function reassign(Request $request, Document $document): JsonResponse
    {
        $user = $request->user();

        $roleName = strtolower($user->roles->first()?->name ?? '');
        $isAdmin = $user->username === 'admin' || $user->hasAnyRole(RoleNames::adminRoles());
        $isSupervisor = $roleName === 'supervisor';
        $isCurrentHolder = $document->assigned_user === $user->full_name
            || $document->assigned_user === $user->id;

        if (! $isAdmin && ! $isSupervisor && ! $isCurrentHolder) {
            return response()->json(['message' => 'You are not authorized to reassign this document.'], 403);
        }

        $validated = $request->validate([
            'assigned_user' => 'required|string',
            'remarks' => 'nullable|string',
        ]);

        $statusBefore = $document->status;

        // Resolve the target user
        $targetUser = User::find($validated['assigned_user']);
        $assignedName = $validated['assigned_user'];
        if ($targetUser) {
            $assignedName = $targetUser->full_name;
        } else {
            $targetUser = $this->resolveAssignedUser($validated['assigned_user']);
            if ($targetUser) {
                $assignedName = $targetUser->full_name;
            }
        }

        $document->update([
            'assigned_user' => $assignedName,
        ]);

        // Create acknowledgement for the new assignee
        if ($targetUser) {
            $acknowledgement = $document->acknowledgements()->create([
                'user_id' => $targetUser->id,
                'assigned_name' => $assignedName,
                'from_office' => $user->office?->name,
                'remarks' => null,
            ]);

            app(DocumentNotificationService::class)->notifyReceived(
                $document,
                $targetUser,
                $user,
                $acknowledgement->id,
            );
        }

        DocumentHistory::record($document, 'REASSIGNED', $user, [
            'to_user_id' => $targetUser?->id,
            'to_division_id' => $targetUser?->division_id,
            'remarks' => $validated['remarks'] ?? null,
            'from_office' => $document->office_assignment,
            'to_office' => $document->office_assignment,
            'status_before' => $statusBefore,
            'status_after' => $document->status,
            'metadata' => [
                'assigned_name' => $assignedName,
            ],
        ]);

        return response()->json([
            'message' => 'Document reassigned successfully.',
            'document' => $document->fresh()->load('attachments'),
        ]);
    }

    /**
     * Admin/supervisor-only: force-send a notification to the assigned user.
     */
    public function forceNotify(Request $request, Document $document): JsonResponse
    {
        $user = $request->user();

        $assignedName = $document->assigned_user;
        $targetUser = null;

        if (! empty($assignedName)) {
            $targetUser = $this->resolveAssignedUser($assignedName);
        }

        if (! $targetUser) {
            $lastAck = $document->acknowledgements()->whereNotNull('user_id')->latest()->first();
            if ($lastAck && $lastAck->user) {
                $targetUser = $lastAck->user;
            }
        }

        if (! $targetUser) {
            return response()->json(['message' => 'Could not resolve the assigned user.'], 422);
        }

        // Delete any existing notifications for this document and user first, so that the unique keys are cleared and a new notification is generated.
        \App\Models\Notification::where('document_id', $document->id)
            ->where('user_id', $targetUser->id)
            ->delete();

        app(DocumentNotificationService::class)->notifyForce($document, $targetUser, $user);

        return response()->json([
            'message' => 'Notification sent.',
        ]);
    }

    /**
     * Get users in the requesting user's office (for admin reassign dropdown).
     */
    public function officeUsers(Request $request): JsonResponse
    {
        $user = $request->user();
        $officeId = $user->office_id;

        if (! $officeId) {
            return response()->json([]);
        }

        $users = User::where('office_id', $officeId)
            ->where('is_active', true)
            ->select('id', 'first_name', 'last_name', 'username', 'position', 'office_id', 'division_id')
            ->orderBy('first_name')
            ->get()
            ->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->full_name,
                'position' => $u->position,
                'division_id' => $u->division_id,
            ]);

        return response()->json($users);
    }

    public function addAttachment(Request $request, Document $document): JsonResponse
    {
        $request->validate([
            'file' => 'required|file',
            'type' => 'nullable|string|max:50',
        ]);

        $file = $request->file('file');
        $disk = config('filesystems.document_disk', 'shared_documents');
        $path = $file ? $file->store("documents/{$document->id}", $disk) : null;
        if (! $path) {
            return response()->json([
                'message' => 'Failed to store attachment file on server. Please verify storage permissions.',
            ], 500);
        }

        $attachment = $document->attachments()->create([
            'disk' => $disk,
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
            'size' => $file->getSize(),
            'type' => $request->input('type', 'attachment'),
        ]);

        DocumentHistory::record($document, DocumentHistory::EDITED, $request->user(), [
            'remarks' => "Added attachment: {$file->getClientOriginalName()}",
        ]);

        return response()->json([
            'message' => 'Attachment added successfully.',
            'attachment' => $attachment,
            'document' => $document->load('attachments'),
        ]);
    }

    public function syncScanSession(Request $request, Document $document): JsonResponse
    {
        $request->validate([
            'scan_session_token' => 'required|string',
        ]);

        $scanPages = ScanPage::where('session_token', $request->scan_session_token)
            ->orderBy('page_number')
            ->get();

        if ($scanPages->isEmpty()) {
            return response()->json([
                'message' => 'No scanned pages found for this session.',
            ], 400);
        }

        $disk = config('filesystems.document_disk', 'shared_documents');
        $defaultDisk = config('filesystems.default', 'local');
        $addedCount = 0;

        foreach ($scanPages as $page) {
            if (Storage::disk($defaultDisk)->exists($page->file_path)) {
                $originalName = basename($page->file_path);
                $mimeType = Storage::disk($defaultDisk)->mimeType($page->file_path) ?? 'image/png';
                $size = Storage::disk($defaultDisk)->size($page->file_path);

                // Copy from temp scanner directory to permanent document disk location
                $newPath = "documents/{$document->id}/{$originalName}";
                Storage::disk($disk)->writeStream(
                    $newPath,
                    Storage::disk($defaultDisk)->readStream($page->file_path)
                );

                $document->attachments()->create([
                    'disk' => $disk,
                    'path' => $newPath,
                    'original_name' => "scanned_page_{$page->page_number}.png",
                    'mime_type' => $mimeType,
                    'size' => $size,
                    'type' => 'scanned_photo',
                ]);
                $addedCount++;
            }
        }

        if ($addedCount > 0) {
            DocumentHistory::record($document, DocumentHistory::EDITED, $request->user(), [
                'remarks' => "Added {$addedCount} phone-scanned pages.",
            ]);
        }

        return response()->json([
            'message' => "Successfully synced {$addedCount} scanned pages.",
            'document' => $document->load('attachments'),
        ]);
    }

    public function deleteAttachment(Request $request, DocumentAttachment $attachment): JsonResponse
    {
        $document = $attachment->document;
        $disk = $attachment->disk ?? 'shared_documents';
        if (Storage::disk($disk)->exists($attachment->path)) {
            Storage::disk($disk)->delete($attachment->path);
        }
        $filename = $attachment->original_name;
        $attachment->delete();

        if ($document) {
            DocumentHistory::record($document, DocumentHistory::EDITED, $request->user(), [
                'remarks' => "Deleted attachment: {$filename}",
            ]);
        }

        return response()->json([
            'message' => 'Attachment deleted successfully.',
            'document' => $document ? $document->load('attachments') : null,
        ]);
    }

    public function unopenedCount(Request $request): JsonResponse
    {
        $user = $request->user();

        $count = DocumentAcknowledgement::query()
            ->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)
                    ->orWhere('assigned_name', $user->full_name);
            })
            ->whereNull('acknowledged_at')
            ->whereHas('document', function ($qDoc) use ($user) {
                $qDoc->where('created_by', '!=', $user->id)
                    ->whereNotIn(\Illuminate\Support\Facades\DB::raw('LOWER(status)'), \App\Support\DocumentStatuses::TERMINAL);
            })
            ->count();

        return response()->json([
            'count' => $count,
        ]);
    }

    public function acknowledge(
        Request $request,
        Document $document,
        DocumentTrackingService $trackingService,
        DocumentNotificationService $notificationService,
    ): JsonResponse {
        $this->authorizeDocumentRead($request, $document, $trackingService);

        $user = $request->user();

        /** @var DocumentAcknowledgement|null $acknowledgement */
        $acknowledgement = $document->acknowledgements()
            ->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)
                    ->orWhere('assigned_name', $user->full_name);
            })
            ->whereNull('acknowledged_at')
            ->first();

        if ($acknowledgement) {
            $acknowledgement->update([
                'acknowledged_at' => now(),
                'remarks' => 'Acknowledged/Opened by recipient.',
            ]);

            $statusBefore = $document->status;
            $newStatus = $document->status;
            if (in_array(strtolower($document->status), ['released', 'outgoing'])) {
                $newStatus = 'pending';
            }

            $document->update([
                'status' => $newStatus,
            ]);
        }

        $notificationService->markAssignmentNotificationsRead($document, $user);

        return response()->json([
            'message' => 'Document acknowledged successfully.',
            'document' => $document->fresh(),
            'unread_notifications' => $user->notifications()->whereNull('read_at')->count(),
        ]);
    }

    private function authorizeDocumentRead(
        Request $request,
        Document $document,
        DocumentTrackingService $trackingService,
    ): void {
        $isAuthorized = $trackingService
            ->buildAuthorizedDocumentQuery($request->user())
            ->whereKey($document->getKey())
            ->exists();

        abort_unless($isAuthorized, 403, 'You are not authorized to view this document.');
    }

    public function receive(Request $request, Document $document): JsonResponse
    {
        $user = $request->user();
        $userOffice = $user->office?->name ?? 'HR Division';
        $userDivision = $user->division?->name;
        $statusBefore = $document->status;
        $fromOffice = $document->office_assignment ?? $document->origin_sender;

        // Check if receiving user is in the target office or same office as current holder / document office
        $docOffice = $document->office_assignment ?? $document->origin_sender ?? $document->creator?->office?->name;
        $isSameOffice = false;
        if ($user->office_id && $document->creator?->office_id && (int) $user->office_id === (int) $document->creator->office_id) {
            $isSameOffice = true;
        } elseif ($userOffice && $docOffice) {
            $userSimilar = Document::getSimilarOfficeNames($userOffice);
            if (in_array($docOffice, $userSimilar, true)) {
                $isSameOffice = true;
            }
        }

        $statusLower = strtolower((string) $document->status);
        if (in_array($statusLower, ['transferred', 'released'], true)) {
            if (! $isSameOffice) {
                return response()->json([
                    'message' => "This document was transmitted to {$docOffice} and has not been received by them yet. Your office cannot receive it.",
                ], 422);
            }
        } elseif (! $isSameOffice) {
            return response()->json([
                'message' => 'Document cannot be received via QR scan because it has not been transferred first.',
            ], 422);
        }

        $document->update([
            'office_assignment' => $userOffice,
            'division_assignment' => $userDivision,
            'assigned_user' => $user->full_name,
            'status' => 'pending',
            'received_at' => $document->received_at ?? Carbon::now(),
        ]);

        $remarks = $request->filled('remarks') ? $request->input('remarks') : null;

        $document->acknowledgements()->create([
            'user_id' => $user->id,
            'assigned_name' => $user->full_name,
            'from_office' => $fromOffice,
            'acknowledged_at' => now(),
            'remarks' => $remarks,
        ]);

        DocumentHistory::record($document, DocumentHistory::RECEIVED, $user, [
            'to_user_id' => $user->id,
            'to_division_id' => $user->division_id,
            'from_office' => $fromOffice,
            'to_office' => $userOffice,
            'remarks' => $remarks,
            'status_before' => $statusBefore,
            'status_after' => 'pending',
            'metadata' => [
                'assigned_name' => $user->full_name,
            ],
        ]);

        return response()->json([
            'message' => 'Document received successfully.',
            'document' => $document->fresh([
                'creator',
                'creator.office',
                'attachments',
                'acknowledgements' => fn ($q) => $q->latest(),
            ]),
        ]);
    }

    public function resolveDocumentByTracking(string $code): ?Document
    {
        $cleaned = trim($code);
        if (preg_match('/(?:track[\/=]|document-tracking\/|documents\/)(DOC-[A-Z0-9_-]+)/i', $cleaned, $matches)) {
            $cleaned = $matches[1];
        } elseif (preg_match('/(DOC-[A-Z0-9_-]+)/i', $cleaned, $matches)) {
            $cleaned = $matches[1];
        }

        // 1. Direct exact or lowercase match / ID match
        $doc = Document::whereRaw('LOWER(tracking_number) = ?', [strtolower($cleaned)])
            ->orWhere('id', $cleaned)
            ->first();

        if ($doc) {
            return $doc;
        }

        // 2. Resilient match: 0 (zero) <-> O (letter O)
        $variantZeroToO = str_replace('0', 'O', strtoupper($cleaned));
        $variantOToZero = str_replace('O', '0', strtoupper($cleaned));

        $doc = Document::whereRaw('UPPER(REPLACE(tracking_number, "0", "O")) = ?', [$variantZeroToO])
            ->orWhereRaw('UPPER(REPLACE(tracking_number, "O", "0")) = ?', [$variantOToZero])
            ->first();

        if ($doc) {
            return $doc;
        }

        // 3. Fallback: match without dashes/spaces with 0/O variations
        $stripped = preg_replace('/[^A-Z0-9]/i', '', $cleaned);
        if ($stripped) {
            $strippedZeroToO = str_replace('0', 'O', strtoupper($stripped));
            $strippedOToZero = str_replace('O', '0', strtoupper($stripped));

            $doc = Document::whereRaw('UPPER(REPLACE(REPLACE(tracking_number, "-", ""), "0", "O")) = ?', [$strippedZeroToO])
                ->orWhereRaw('UPPER(REPLACE(REPLACE(tracking_number, "-", ""), "O", "0")) = ?', [$strippedOToZero])
                ->first();

            if ($doc) {
                return $doc;
            }
        }

        return null;
    }

    public function receiveByCode(Request $request): JsonResponse
    {
        $request->validate([
            'code' => 'required|string',
            'remarks' => 'nullable|string',
        ]);

        $rawCode = trim((string) $request->input('code'));
        $document = $this->resolveDocumentByTracking($rawCode);

        if (! $document) {
            return response()->json([
                'message' => "No document found matching tracking number '{$rawCode}'.",
            ], 404);
        }

        return $this->receive($request, $document);
    }

    public function publicTrack(Request $request, string $trackingNumber): JsonResponse
    {
        $document = $this->resolveDocumentByTracking($trackingNumber);

        if (! $document) {
            return response()->json([
                'message' => "No document found matching tracking number '{$trackingNumber}'.",
            ], 404);
        }

        $document->loadMissing([
            'creator',
            'creator.office',
            'attachments',
            'acknowledgements' => fn ($q) => $q->latest(),
        ]);

        $events = DocumentHistory::query()
            ->where('document_id', $document->id)
            ->with([
                'user:id,first_name,last_name,username,position,is_active,office_id',
                'user.office:id,name,short_name',
                'fromUser:id,first_name,last_name,username,position,is_active,office_id',
                'fromUser.office:id,name,short_name',
                'toUser:id,first_name,last_name,username,position,is_active,office_id',
                'toUser.office:id,name,short_name',
            ])
            ->latest('created_at')
            ->orderByDesc('id')
            ->get();

        $timelineEvents = $events
            ->map(fn (DocumentHistory $event) => $this->timelineEventPayload($event, true))
            ->values();

        return response()->json([
            'document' => [
                'id' => $document->id,
                'tracking_number' => $document->tracking_number,
                'particular' => $document->particular ?? $document->title ?? $document->description,
                'description' => $document->description,
                'type' => $document->document_type ?? $document->type ?? 'N/A',
                'status' => $document->status,
                'priority' => $document->priority_level ?? $document->priority ?? 'normal',
                'origin' => $document->origin ?? $document->origin_sender ?? 'N/A',
                'contact_person' => $document->contact_person ?? $document->assigned_user ?? 'N/A',
                'contact_number' => $document->contact_number ?? 'N/A',
                'issued_by' => $document->issued_by ?? 'N/A',
                'office_assignment' => $document->office_assignment,
                'division_assignment' => $document->division_assignment,
                'assigned_user' => $document->assigned_user,
                'attachments' => $document->attachments,
                'created_at' => $document->created_at?->format('M d, Y h:i A'),
            ],
            'timeline' => $timelineEvents,
        ]);
    }

    public function getByTracking(Request $request, string $trackingNumber): JsonResponse
    {
        $document = $this->resolveDocumentByTracking($trackingNumber);

        if (! $document) {
            return response()->json([
                'message' => 'Document not found.',
            ], 404);
        }

        $document->loadMissing([
            'creator',
            'creator.office',
            'attachments',
            'acknowledgements' => fn ($q) => $q->latest(),
        ]);

        return response()->json([
            'data' => $document,
        ]);
    }

    public function addRemark(Request $request, Document $document): JsonResponse
    {
        $validated = $request->validate([
            'remarks' => 'required|string',
        ]);

        $cleanRemark = trim($validated['remarks']);
        if ($cleanRemark === '') {
            return response()->json(['message' => 'Remark cannot be empty.'], 422);
        }

        DocumentHistory::record($document, DocumentHistory::REMARKED, $request->user(), [
            'remarks' => $cleanRemark,
        ]);

        return response()->json([
            'message' => 'Remark added successfully.',
            'document' => $document->fresh(),
        ]);
    }




    private function isAutoRemark(?string $remarks): bool
    {
        if ($remarks === null) {
            return true;
        }
        $trimmed = trim($remarks);
        if ($trimmed === '') {
            return true;
        }
        $autoRemarks = [
            'document marked as complete.',
            'document received.',
            'document physically received via qr scan.',
            'document physically released.',
            'document registered.',
            'pending acknowledgement.',
            'pending acknowledgement (released).',
            'pending acknowledgement (transferred).',
            'pending acknowledgement (transmitted).',
            'pending acknowledgement (returned).',
            'pending acknowledgement (reassigned).',
            'document transferred.',
            'document transmitted.',
            'document returned to sender.',
            'document archived.',
            'document retrieved from completed.',
            'document shared.',
            'acknowledged/opened by recipient.',
        ];
        if (in_array(strtolower($trimmed), $autoRemarks)) {
            return true;
        }
        if (str_starts_with(strtolower($trimmed), 'document reassigned to')) {
            return true;
        }
        return false;
    }
}
