<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <title>Document Capture – HR Document Tracking</title>
  <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
  <style>
    /* ── Reset & Base ── */
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html, body {
      width: 100%; height: 100%;
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
      background: #000; color: #fff;
      overflow: hidden; touch-action: manipulation;
      -webkit-user-select: none; user-select: none;
    }

    /* ── Screen containers ── */
    .screen { display: none; width: 100%; height: 100%; flex-direction: column; position: absolute; inset: 0; }
    .screen.active { display: flex; }

    /* ═══════════════════════════════════════════════════════════════════════════
       SCREEN A – CAMERA VIEWFINDER
       ═══════════════════════════════════════════════════════════════════════════ */
    #screen-camera { background: #000; }

    /* Header */
    .cam-header {
      background: #036431; padding: 12px 16px;
      display: flex; align-items: center; justify-content: space-between;
      flex-shrink: 0; z-index: 10;
    }
    .cam-header-left { display: flex; align-items: center; gap: 10px; }
    .cam-header-left .material-icons { font-size: 24px; color: #a7f3d0; }
    .cam-header-text h1 { font-size: 16px; font-weight: 700; line-height: 1.2; }
    .cam-header-text p { font-size: 11px; color: #a7f3d0; margin-top: 2px; }
    .linked-badge {
      display: flex; align-items: center; gap: 5px;
      background: rgba(255,255,255,0.15); border-radius: 20px;
      padding: 4px 10px; font-size: 12px; font-weight: 600;
    }
    .linked-badge .dot { width: 8px; height: 8px; border-radius: 50%; background: #4ade80; animation: pulse 2s infinite; }
    @keyframes pulse { 0%,100%{opacity:1} 50%{opacity:0.4} }

    /* Viewfinder */
    .viewfinder-wrap {
      flex: 1; position: relative; overflow: hidden;
      display: flex; align-items: center; justify-content: center;
    }
    .viewfinder-wrap video {
      width: 100%; height: 100%; object-fit: cover;
    }
    .viewfinder-overlay {
      position: absolute; inset: 0; pointer-events: none;
      display: flex; flex-direction: column; align-items: center; justify-content: center;
    }
    .guide-text {
      font-size: 13px; color: rgba(255,255,255,0.8);
      background: rgba(0,0,0,0.5); padding: 6px 14px;
      border-radius: 20px; margin-bottom: 20px;
    }
    .guide-rect {
      width: 88%; max-width: 340px; aspect-ratio: 3/4.2;
      border: 3px dashed rgba(74,222,128,0.7);
      border-radius: 8px; position: relative;
    }
    .guide-rect::before {
      content: ''; position: absolute; inset: -1px;
      border: 1px solid rgba(74,222,128,0.3); border-radius: 10px;
    }

    /* Controls */
    .cam-controls {
      background: #000; padding: 12px 20px 20px;
      flex-shrink: 0; display: flex; flex-direction: column; gap: 10px;
    }
    .bw-row {
      display: flex; align-items: center; justify-content: space-between;
      padding: 0 4px;
    }
    .bw-row label { font-size: 13px; font-weight: 600; display: flex; align-items: center; gap: 6px; }
    .status-pill {
      display: flex; align-items: center; gap: 5px;
      background: #166534; color: #4ade80;
      padding: 6px 14px; border-radius: 20px;
      font-size: 12px; font-weight: 600;
      align-self: center; opacity: 0; transition: opacity 0.3s;
    }
    .status-pill.show { opacity: 1; }

    .shutter-row {
      display: flex; align-items: center; justify-content: center;
      gap: 24px; padding: 4px 0;
    }
    .thumb-box {
      width: 52px; height: 52px; border-radius: 8px;
      border: 2px solid #333; overflow: hidden; position: relative;
      background: #111; display: flex; align-items: center; justify-content: center;
    }
    .thumb-box img { width: 100%; height: 100%; object-fit: cover; }
    .thumb-box .count-badge {
      position: absolute; top: -4px; right: -4px;
      background: #166534; color: #fff; font-size: 10px; font-weight: 700;
      width: 18px; height: 18px; border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
    }
    .shutter-btn {
      width: 68px; height: 68px; border-radius: 50%;
      border: 4px solid #fff; background: transparent;
      cursor: pointer; position: relative; transition: transform 0.15s;
    }
    .shutter-btn:active { transform: scale(0.9); }
    .shutter-btn::after {
      content: ''; position: absolute; inset: 5px;
      border-radius: 50%; background: #fff;
      transition: background 0.15s;
    }
    .shutter-btn:active::after { background: #ccc; }
    .switch-cam-btn {
      width: 40px; height: 40px; border-radius: 50%;
      border: 2px solid #555; background: transparent;
      color: #fff; display: flex; align-items: center; justify-content: center;
      cursor: pointer;
    }

    .done-btn-row { display: flex; justify-content: center; }
    .done-btn {
      width: 100%; max-width: 320px; padding: 14px;
      border: none; border-radius: 12px; font-size: 15px;
      font-weight: 700; cursor: pointer;
      display: flex; align-items: center; justify-content: center; gap: 8px;
      transition: background 0.2s;
    }
    .done-btn.disabled {
      background: #333; color: #666; cursor: not-allowed;
    }
    .done-btn.active {
      background: #16a34a; color: #fff;
    }
    .done-btn:disabled { cursor: not-allowed; }

    /* ═══════════════════════════════════════════════════════════════════════════
       SCREEN B – DRAG & CROP
       ═══════════════════════════════════════════════════════════════════════════ */
    #screen-crop { background: #111; }

    .crop-header {
      padding: 20px 20px 12px; flex-shrink: 0;
    }
    .crop-header h2 { font-size: 22px; font-weight: 800; }
    .crop-header p { font-size: 14px; color: #aaa; margin-top: 4px; }

    .crop-canvas-wrap {
      flex: 1; display: flex; align-items: center; justify-content: center;
      position: relative; overflow: hidden; padding: 8px;
    }
    .crop-canvas-wrap canvas {
      max-width: 100%; max-height: 100%;
      touch-action: none;
    }

    .crop-footer {
      background: #000; padding: 12px 20px 24px;
      display: flex; gap: 12px; flex-shrink: 0;
    }
    .crop-footer button {
      flex: 1; padding: 14px 8px; border: none; border-radius: 12px;
      font-size: 14px; font-weight: 700; cursor: pointer;
      display: flex; align-items: center; justify-content: center; gap: 6px;
    }
    .crop-footer .btn-secondary { background: #333; color: #fff; }
    .crop-footer .btn-primary { background: #16a34a; color: #fff; }

    /* ═══════════════════════════════════════════════════════════════════════════
       SCREEN C – PREVIEW & CLEAN
       ═══════════════════════════════════════════════════════════════════════════ */
    #screen-preview { background: #111; }

    .preview-header {
      padding: 20px 20px 12px; flex-shrink: 0;
    }
    .preview-header h2 { font-size: 22px; font-weight: 800; }
    .preview-header p { font-size: 14px; color: #aaa; margin-top: 4px; }

    .preview-canvas-wrap {
      flex: 1; display: flex; align-items: center; justify-content: center;
      position: relative; overflow: hidden; padding: 8px;
    }
    .preview-canvas-wrap canvas {
      max-width: 100%; max-height: 100%;
    }
    .adjust-crop-overlay {
      position: absolute; bottom: 50%; left: 50%;
      transform: translate(-50%, 50%);
      background: rgba(0,0,0,0.6); border: 2px solid rgba(255,255,255,0.3);
      border-radius: 24px; padding: 8px 18px;
      display: flex; align-items: center; gap: 6px;
      font-size: 13px; font-weight: 600; cursor: pointer;
    }

    .preview-footer {
      background: #000; padding: 12px 20px 24px;
      display: flex; gap: 12px; flex-shrink: 0;
    }
    .preview-footer button {
      flex: 1; padding: 14px 8px; border: none; border-radius: 12px;
      font-size: 14px; font-weight: 700; cursor: pointer;
      display: flex; align-items: center; justify-content: center; gap: 6px;
    }
    .preview-footer .btn-secondary { background: #333; color: #fff; }
    .preview-footer .btn-primary { background: #16a34a; color: #fff; }

    /* ── Loading Overlay ── */
    .loading-overlay {
      position: fixed; inset: 0; background: rgba(0,0,0,0.7);
      display: none; align-items: center; justify-content: center;
      flex-direction: column; gap: 12px; z-index: 999;
    }
    .loading-overlay.show { display: flex; }
    .spinner { width: 40px; height: 40px; border: 4px solid #333; border-top-color: #4ade80; border-radius: 50%; animation: spin 0.8s linear infinite; }
    @keyframes spin { to { transform: rotate(360deg); } }

    /* ── Not Found / Invalid Session ── */
    .not-found-screen {
      display: flex; flex-direction: column; align-items: center;
      justify-content: center; height: 100%; gap: 16px; padding: 40px;
      text-align: center;
    }
    .not-found-screen .material-icons { font-size: 64px; color: #555; }
    .not-found-screen h2 { font-size: 20px; }
    .not-found-screen p { color: #888; font-size: 14px; }

    /* ── Completion Screen ── */
    .complete-screen {
      display: flex; flex-direction: column; align-items: center;
      justify-content: center; height: 100%; gap: 16px; padding: 40px;
      text-align: center;
    }
    .complete-screen .material-icons { font-size: 80px; color: #4ade80; }
    .complete-screen h2 { font-size: 22px; font-weight: 700; }
    .complete-screen p { color: #aaa; font-size: 14px; }
  </style>
</head>
<body>

  <!-- Loading Overlay -->
  <div class="loading-overlay" id="loadingOverlay">
    <div class="spinner"></div>
    <span style="color:#aaa;font-size:14px;" id="loadingText">Processing...</span>
  </div>

  <!-- ═══════════ SCREEN A: CAMERA ═══════════ -->
  <div class="screen active" id="screen-camera">
    <div class="cam-header">
      <div class="cam-header-left">
        <span class="material-icons">folder</span>
        <div class="cam-header-text">
          <h1>Document Capture</h1>
          <p>HR Document Tracking</p>
        </div>
      </div>
      <div class="linked-badge">
        <span class="dot"></span>
        <span>Linked</span>
      </div>
    </div>

    <div class="viewfinder-wrap">
      <video id="cameraVideo" autoplay playsinline muted></video>
      <div class="viewfinder-overlay">
        <div class="guide-text">Fit the whole page in view, with a little space around it</div>
        <div class="guide-rect"></div>
      </div>
    </div>

    <div class="cam-controls">
      <div class="bw-row">
        <label>
          <span class="material-icons" style="font-size:18px">contrast</span>
          Scan look (B&W)
        </label>
        <label style="position:relative;width:44px;height:24px;">
          <input type="checkbox" id="bwToggle" style="opacity:0;width:0;height:0;">
          <span style="position:absolute;inset:0;background:#333;border-radius:12px;cursor:pointer;transition:0.3s;" id="bwTrack"></span>
          <span style="position:absolute;top:3px;left:3px;width:18px;height:18px;background:#fff;border-radius:50%;transition:0.3s;" id="bwThumb"></span>
        </label>
      </div>

      <div class="status-pill" id="statusPill">
        <span class="material-icons" style="font-size:16px">check_circle</span>
        <span id="statusPillText">Page sent to desktop</span>
      </div>

      <div class="shutter-row">
        <div class="thumb-box" id="thumbBox">
          <span class="material-icons" style="color:#555;font-size:24px">photo_camera</span>
          <div class="count-badge" id="thumbCount" style="display:none">0</div>
        </div>

        <button class="shutter-btn" id="shutterBtn" aria-label="Capture"></button>

        <button class="switch-cam-btn" id="switchCamBtn" aria-label="Switch Camera">
          <span class="material-icons" style="font-size:20px">cameraswitch</span>
        </button>
      </div>

      <div class="done-btn-row">
        <button class="done-btn disabled" id="doneBtn" disabled>
          <span class="material-icons">check</span>
          <span id="doneBtnText">Capture a page to continue</span>
        </button>
      </div>
    </div>
  </div>

  <!-- ═══════════ SCREEN B: CROP ═══════════ -->
  <div class="screen" id="screen-crop">
    <div class="crop-header">
      <h2>Drag the corners to the page</h2>
      <p>Put a handle on each corner of the document.</p>
    </div>
    <div class="crop-canvas-wrap" id="cropCanvasWrap">
      <canvas id="cropCanvas"></canvas>
    </div>
    <div class="crop-footer">
      <button class="btn-secondary" id="wholePhotoBtn">
        <span class="material-icons" style="font-size:18px">fullscreen</span>
        Whole photo
      </button>
      <button class="btn-secondary" id="cropCancelBtn">
        <span class="material-icons" style="font-size:18px">close</span>
        Cancel
      </button>
      <button class="btn-primary" id="cropApplyBtn">
        <span class="material-icons" style="font-size:18px">check</span>
        Apply crop
      </button>
    </div>
  </div>

  <!-- ═══════════ SCREEN C: PREVIEW ═══════════ -->
  <div class="screen" id="screen-preview">
    <div class="preview-header">
      <h2>Looks good?</h2>
      <p>Cropping &amp; cleaning...</p>
    </div>
    <div class="preview-canvas-wrap" id="previewCanvasWrap">
      <canvas id="previewCanvas"></canvas>
      <div class="adjust-crop-overlay" id="adjustCropBtn">
        <span class="material-icons" style="font-size:18px">crop_free</span>
        Adjust crop
      </div>
    </div>
    <div class="preview-footer">
      <button class="btn-secondary" id="retakeBtn">
        <span class="material-icons" style="font-size:18px">replay</span>
        Retake
      </button>
      <button class="btn-primary" id="addDocBtn">
        <span class="material-icons" style="font-size:18px">send</span>
        Add to document
      </button>
    </div>
  </div>

  <!-- ═══════════ COMPLETION SCREEN ═══════════ -->
  <div class="screen" id="screen-complete">
    <div class="complete-screen">
      <span class="material-icons">check_circle</span>
      <h2>All done!</h2>
      <p>Your scanned pages have been sent to the desktop.</p>
      <p style="margin-top:8px;color:#666;font-size:13px;">You may close this tab.</p>
    </div>
  </div>

  <!-- ═══════════ INVALID SESSION SCREEN ═══════════ -->
  <div class="screen" id="screen-invalid">
    <div class="not-found-screen">
      <span class="material-icons">error_outline</span>
      <h2>Invalid or Expired Link</h2>
      <p>This scan session is no longer valid. Please scan the QR code again from the desktop application.</p>
    </div>
  </div>

  <script>
    /* ═══════════════════════════════════════════════════════════════════════════
       INITIALIZATION
       ═══════════════════════════════════════════════════════════════════════════ */
    const params = new URLSearchParams(window.location.search);
    const SESSION_TOKEN = params.get('session');
    const API_BASE = window.location.origin + '/api';

    let cameraStream = null;
    let facingMode = 'environment'; // 'environment' = back camera, 'user' = front
    let capturedImage = null;      // HTMLImageElement of the last capture
    let cropCorners = null;        // [{x,y}, ...] 4 corners for perspective crop
    let bwEnabled = false;
    let pageCount = 0;
    let isUploading = false;

    // Elements
    const video = document.getElementById('cameraVideo');
    const screens = {
      camera: document.getElementById('screen-camera'),
      crop: document.getElementById('screen-crop'),
      preview: document.getElementById('screen-preview'),
      complete: document.getElementById('screen-complete'),
      invalid: document.getElementById('screen-invalid'),
    };

    /* ── Validate session ── */
    if (!SESSION_TOKEN) {
      showScreen('invalid');
    } else {
      validateSession();
    }

    async function validateSession() {
      try {
        const res = await fetch(`${API_BASE}/scan-session/${SESSION_TOKEN}`);
        if (!res.ok) throw new Error('not found');
        const data = await res.json();
        // Session exists – mark as connected
        await fetch(`${API_BASE}/scan-session/${SESSION_TOKEN}/status`, {
          method: 'PUT',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ status: 'connected' }),
        });
        startCamera();
      } catch {
        showScreen('invalid');
      }
    }

    function showScreen(name) {
      Object.values(screens).forEach(s => s.classList.remove('active'));
      screens[name]?.classList.add('active');
    }

    /* ═══════════════════════════════════════════════════════════════════════════
       CAMERA
       ═══════════════════════════════════════════════════════════════════════════ */
    async function startCamera() {
      try {
        if (cameraStream) {
          cameraStream.getTracks().forEach(t => t.stop());
        }
        cameraStream = await navigator.mediaDevices.getUserMedia({
          video: { facingMode, width: { ideal: 1920 }, height: { ideal: 2560 } },
          audio: false,
        });
        video.srcObject = cameraStream;
        showScreen('camera');
      } catch (err) {
        console.error('Camera error:', err);
        alert('Could not access camera. Please allow camera permissions and try again.');
      }
    }

    document.getElementById('switchCamBtn').addEventListener('click', () => {
      facingMode = facingMode === 'environment' ? 'user' : 'environment';
      startCamera();
    });

    /* ── BW Toggle ── */
    const bwToggle = document.getElementById('bwToggle');
    const bwTrack = document.getElementById('bwTrack');
    const bwThumb = document.getElementById('bwThumb');
    bwToggle.addEventListener('change', () => {
      bwEnabled = bwToggle.checked;
      bwTrack.style.background = bwEnabled ? '#16a34a' : '#333';
      bwThumb.style.transform = bwEnabled ? 'translateX(20px)' : 'translateX(0)';
    });

    /* ── Shutter ── */
    document.getElementById('shutterBtn').addEventListener('click', captureImage);

    function captureImage() {
      if (!cameraStream) return;

      const track = cameraStream.getVideoTracks()[0];
      const settings = track.getSettings();
      const w = settings.width || video.videoWidth;
      const h = settings.height || video.videoHeight;

      const canvas = document.createElement('canvas');
      canvas.width = w;
      canvas.height = h;
      const ctx = canvas.getContext('2d');
      ctx.drawImage(video, 0, 0, w, h);

      // Store as image
      const img = new Image();
      img.onload = () => {
        capturedImage = img;
        initCropScreen(img);
        showScreen('crop');
      };
      img.src = canvas.toDataURL('image/jpeg', 0.95);
    }

    /* ═══════════════════════════════════════════════════════════════════════════
       SCREEN B – DRAG & CROP (Perspective Crop)
       ═══════════════════════════════════════════════════════════════════════════ */
    const cropCanvas = document.getElementById('cropCanvas');
    const cropCtx = cropCanvas.getContext('2d');
    let dragIndex = -1;
    let cropScale = 1;
    let cropOffsetX = 0;
    let cropOffsetY = 0;

    function initCropScreen(img) {
      // Size canvas to fit the container
      const wrap = document.getElementById('cropCanvasWrap');
      const maxW = wrap.clientWidth - 16;
      const maxH = wrap.clientHeight - 16;

      let cw = img.naturalWidth;
      let ch = img.naturalHeight;

      // Scale to fit
      const ratio = Math.min(maxW / cw, maxH / ch, 1);
      cw = Math.round(cw * ratio);
      ch = Math.round(ch * ratio);

      cropCanvas.width = cw;
      cropCanvas.height = ch;
      cropScale = img.naturalWidth / cw;
      cropOffsetX = 0;
      cropOffsetY = 0;

      // Default corners: 10% inset from edges
      const margin = 0.10;
      cropCorners = [
        { x: cw * margin,      y: ch * margin },       // top-left
        { x: cw * (1 - margin), y: ch * margin },       // top-right
        { x: cw * (1 - margin), y: ch * (1 - margin) }, // bottom-right
        { x: cw * margin,      y: ch * (1 - margin) },  // bottom-left
      ];

      drawCropCanvas(img);
    }

    function drawCropCanvas(img) {
      const cw = cropCanvas.width;
      const ch = cropCanvas.height;

      cropCtx.clearRect(0, 0, cw, ch);
      cropCtx.drawImage(img, 0, 0, cw, ch);

      // Draw quadrilateral overlay
      cropCtx.save();
      cropCtx.beginPath();
      cropCtx.moveTo(cropCorners[0].x, cropCorners[0].y);
      for (let i = 1; i < 4; i++) {
        cropCtx.lineTo(cropCorners[i].x, cropCorners[i].y);
      }
      cropCtx.closePath();
      cropCtx.fillStyle = 'rgba(74, 222, 128, 0.12)';
      cropCtx.fill();
      cropCtx.strokeStyle = 'rgba(74, 222, 128, 0.8)';
      cropCtx.lineWidth = 2;
      cropCtx.stroke();
      cropCtx.restore();

      // Draw corner handles
      const handleR = 14;
      cropCorners.forEach((c, i) => {
        cropCtx.beginPath();
        cropCtx.arc(c.x, c.y, handleR, 0, Math.PI * 2);
        cropCtx.fillStyle = '#4ade80';
        cropCtx.fill();
        cropCtx.strokeStyle = '#fff';
        cropCtx.lineWidth = 2;
        cropCtx.stroke();
      });
    }

    /* ── Touch / Mouse drag for corners ── */
    function getCanvasCoords(e) {
      const rect = cropCanvas.getBoundingClientRect();
      const clientX = e.touches ? e.touches[0].clientX : e.clientX;
      const clientY = e.touches ? e.touches[0].clientY : e.clientY;
      return {
        x: (clientX - rect.left) * (cropCanvas.width / rect.width),
        y: (clientY - rect.top) * (cropCanvas.height / rect.height),
      };
    }

    function findNearestCorner(coords) {
      const threshold = 30; // pixels
      let minDist = Infinity;
      let idx = -1;
      cropCorners.forEach((c, i) => {
        const d = Math.hypot(c.x - coords.x, c.y - coords.y);
        if (d < threshold && d < minDist) { minDist = d; idx = i; }
      });
      return idx;
    }

    function onCropStart(e) {
      e.preventDefault();
      const coords = getCanvasCoords(e);
      dragIndex = findNearestCorner(coords);
    }

    function onCropMove(e) {
      if (dragIndex < 0) return;
      e.preventDefault();
      const coords = getCanvasCoords(e);
      cropCorners[dragIndex].x = Math.max(0, Math.min(cropCanvas.width, coords.x));
      cropCorners[dragIndex].y = Math.max(0, Math.min(cropCanvas.height, coords.y));
      drawCropCanvas(capturedImage);
    }

    function onCropEnd() {
      dragIndex = -1;
    }

    cropCanvas.addEventListener('mousedown', onCropStart);
    cropCanvas.addEventListener('mousemove', onCropMove);
    cropCanvas.addEventListener('mouseup', onCropEnd);
    cropCanvas.addEventListener('mouseleave', onCropEnd);
    cropCanvas.addEventListener('touchstart', onCropStart, { passive: false });
    cropCanvas.addEventListener('touchmove', onCropMove, { passive: false });
    cropCanvas.addEventListener('touchend', onCropEnd);

    /* ── Whole Photo button ── */
    document.getElementById('wholePhotoBtn').addEventListener('click', () => {
      const cw = cropCanvas.width;
      const ch = cropCanvas.height;
      cropCorners = [
        { x: 0, y: 0 },
        { x: cw, y: 0 },
        { x: cw, y: ch },
        { x: 0, y: ch },
      ];
      drawCropCanvas(capturedImage);
    });

    /* ── Cancel ── */
    document.getElementById('cropCancelBtn').addEventListener('click', () => {
      capturedImage = null;
      showScreen('camera');
    });

    /* ── Apply Crop ── */
    document.getElementById('cropApplyBtn').addEventListener('click', () => {
      applyPerspectiveCrop(capturedImage, cropCorners);
    });

    /**
     * Perspective warp: maps the 4-point quadrangle to a clean rectangle.
     * Uses bilinear interpolation for pixel mapping.
     */
    function applyPerspectiveCrop(img, srcCorners) {
      showLoading('Cropping...');

      requestAnimationFrame(() => {
        try {
          // Convert source corners to original image coordinates
          const src = srcCorners.map(c => ({
            x: c.x * cropScale,
            y: c.y * cropScale,
          }));

          // Calculate output dimensions based on longest edges
          const topLen = Math.hypot(src[1].x - src[0].x, src[1].y - src[0].y);
          const botLen = Math.hypot(src[2].x - src[3].x, src[2].y - src[3].y);
          const leftLen = Math.hypot(src[3].x - src[0].x, src[3].y - src[0].y);
          const rightLen = Math.hypot(src[2].x - src[1].x, src[2].y - src[1].y);

          const outW = Math.round(Math.max(topLen, botLen));
          const outH = Math.round(Math.max(leftLen, rightLen));

          // Destination rectangle corners (clockwise from top-left)
          const dst = [
            { x: 0, y: 0 },
            { x: outW, y: 0 },
            { x: outW, y: outH },
            { x: 0, y: outH },
          ];

          // Compute homography matrix using 4 point correspondences
          const H = computeHomography(src, dst);

          // Create output canvas
          const outCanvas = document.createElement('canvas');
          outCanvas.width = outW;
          outCanvas.height = outH;
          const outCtx = outCanvas.getContext('2d');
          const outData = outCtx.createImageData(outW, outH);

          // Source image data
          const tmpCanvas = document.createElement('canvas');
          tmpCanvas.width = img.naturalWidth;
          tmpCanvas.height = img.naturalHeight;
          const tmpCtx = tmpCanvas.getContext('2d');
          tmpCtx.drawImage(img, 0, 0);
          const srcData = tmpCtx.getImageData(0, 0, tmpCanvas.width, tmpCanvas.height);
          const srcPixels = srcData.data;

          // Warp pixels
          for (let y = 0; y < outH; y++) {
            for (let x = 0; x < outW; x++) {
              // Apply inverse homography to find source coordinate
              const denom = H[6] * x + H[7] * y + H[8];
              const srcX = (H[0] * x + H[1] * y + H[2]) / denom;
              const srcY = (H[3] * x + H[4] * y + H[5]) / denom;

              // Bilinear interpolation
              const x0 = Math.floor(srcX);
              const y0 = Math.floor(srcY);
              const x1 = x0 + 1;
              const y1 = y0 + 1;
              const fx = srcX - x0;
              const fy = srcY - y0;

              if (x0 >= 0 && x1 < tmpCanvas.width && y0 >= 0 && y1 < tmpCanvas.height) {
                const w = (y * outW + x) * 4;
                for (let ch = 0; ch < 3; ch++) {
                  const v00 = srcPixels[(y0 * tmpCanvas.width + x0) * 4 + ch];
                  const v10 = srcPixels[(y0 * tmpCanvas.width + x1) * 4 + ch];
                  const v01 = srcPixels[(y1 * tmpCanvas.width + x0) * 4 + ch];
                  const v11 = srcPixels[(y1 * tmpCanvas.width + x1) * 4 + ch];
                  outData.data[w + ch] = Math.round(
                    v00 * (1 - fx) * (1 - fy) +
                    v10 * fx * (1 - fy) +
                    v01 * (1 - fx) * fy +
                    v11 * fx * fy
                  );
                }
                outData.data[w + 3] = 255; // alpha
              }
            }
          }

          outCtx.putImageData(outData, 0, 0);

          // Show in preview
          showPreviewScreen(outCanvas);
          hideLoading();
        } catch (err) {
          console.error('Crop error:', err);
          hideLoading();
          alert('Cropping failed. Please try again.');
        }
      });
    }

    /**
     * Compute 3x3 homography matrix from 4 source→destination point pairs.
     */
    function computeHomography(src, dst) {
      // Build the 8x8 system for the 8 unknowns of the homography
      const A = [];
      const b = [];

      for (let i = 0; i < 4; i++) {
        const sx = src[i].x, sy = src[i].y;
        const dx = dst[i].x, dy = dst[i].y;

        A.push([sx, sy, 1, 0, 0, 0, -dx * sx, -dx * sy]);
        b.push(dx);

        A.push([0, 0, 0, sx, sy, 1, -dy * sx, -dy * sy]);
        b.push(dy);
      }

      // Solve using Gaussian elimination
      const n = 8;
      const aug = A.map((row, i) => [...row, b[i]]);

      for (let col = 0; col < n; col++) {
        // Find pivot
        let maxRow = col;
        for (let row = col + 1; row < n; row++) {
          if (Math.abs(aug[row][col]) > Math.abs(aug[maxRow][col])) maxRow = row;
        }
        [aug[col], aug[maxRow]] = [aug[maxRow], aug[col]];

        const pivot = aug[col][col];
        if (Math.abs(pivot) < 1e-10) continue;

        for (let j = col; j <= n; j++) aug[col][j] /= pivot;

        for (let row = 0; row < n; row++) {
          if (row === col) continue;
          const factor = aug[row][col];
          for (let j = col; j <= n; j++) aug[row][j] -= factor * aug[col][j];
        }
      }

      const h = aug.map(row => row[n]);
      h.push(1); // h[8] = 1
      return h;
    }

    /* ═══════════════════════════════════════════════════════════════════════════
       SCREEN C – PREVIEW & CLEAN
       ═══════════════════════════════════════════════════════════════════════════ */
    let croppedCanvas = null; // The warped canvas from crop step

    function showPreviewScreen(warpedCanvas) {
      croppedCanvas = warpedCanvas;

      const previewCanvas = document.getElementById('previewCanvas');
      const ctx = previewCanvas.getContext('2d');

      // Scale to fit
      const wrap = document.getElementById('previewCanvasWrap');
      const maxW = wrap.clientWidth - 16;
      const maxH = wrap.clientHeight - 60; // leave room for overlay button
      const ratio = Math.min(maxW / warpedCanvas.width, maxH / warpedCanvas.height, 1);
      previewCanvas.width = Math.round(warpedCanvas.width * ratio);
      previewCanvas.height = Math.round(warpedCanvas.height * ratio);

      ctx.drawImage(warpedCanvas, 0, 0, previewCanvas.width, previewCanvas.height);

      // Apply B&W if enabled
      if (bwEnabled) {
        applyThresholdFilter(ctx, previewCanvas.width, previewCanvas.height);
      }

      showScreen('preview');
    }

    /**
     * High-contrast B&W threshold filter.
     */
    function applyThresholdFilter(ctx, w, h) {
      const imgData = ctx.getImageData(0, 0, w, h);
      const d = imgData.data;
      const threshold = 128;

      for (let i = 0; i < d.length; i += 4) {
        const gray = 0.2126 * d[i] + 0.7152 * d[i + 1] + 0.0722 * d[i + 2];
        const v = gray >= threshold ? 255 : 0;
        d[i] = d[i + 1] = d[i + 2] = v;
      }

      ctx.putImageData(imgData, 0, 0);
    }

    /* ── Adjust Crop ── */
    document.getElementById('adjustCropBtn').addEventListener('click', () => {
      showScreen('crop');
    });

    /* ── Retake ── */
    document.getElementById('retakeBtn').addEventListener('click', () => {
      capturedImage = null;
      croppedCanvas = null;
      showScreen('camera');
    });

    /* ── Add to Document (Upload) ── */
    document.getElementById('addDocBtn').addEventListener('click', uploadPage);

    async function uploadPage() {
      if (isUploading || !croppedCanvas) return;
      isUploading = true;

      showLoading('Uploading page...');

      try {
        // Convert canvas to blob
        const blob = await new Promise(resolve =>
          croppedCanvas.toBlob(resolve, 'image/jpeg', 0.92)
        );

        pageCount++;
        const formData = new FormData();
        formData.append('image', blob, `page_${pageCount}.jpg`);
        formData.append('page_number', pageCount);

        const res = await fetch(`${API_BASE}/scan-session/${SESSION_TOKEN}/pages`, {
          method: 'POST',
          body: formData,
        });

        if (!res.ok) throw new Error('Upload failed');

        // Show status pill
        showStatusPill(`Page ${pageCount} sent to desktop`);

        // Update thumbnail
        updateThumbnail(croppedCanvas);

        // Reset
        capturedImage = null;
        croppedCanvas = null;
        isUploading = false;
        hideLoading();

        // Back to camera
        showScreen('camera');
        updateDoneButton();
      } catch (err) {
        console.error('Upload error:', err);
        hideLoading();
        isUploading = false;
        alert('Failed to upload page. Please try again.');
      }
    }

    /* ═══════════════════════════════════════════════════════════════════════════
       UI HELPERS
       ═══════════════════════════════════════════════════════════════════════════ */
    function showLoading(text) {
      document.getElementById('loadingText').textContent = text || 'Processing...';
      document.getElementById('loadingOverlay').classList.add('show');
    }

    function hideLoading() {
      document.getElementById('loadingOverlay').classList.remove('show');
    }

    function showStatusPill(text) {
      const pill = document.getElementById('statusPill');
      document.getElementById('statusPillText').textContent = text;
      pill.classList.add('show');
      setTimeout(() => pill.classList.remove('show'), 2500);
    }

    function updateThumbnail(canvas) {
      const box = document.getElementById('thumbBox');
      box.innerHTML = '';
      const img = document.createElement('img');
      img.src = canvas.toDataURL('image/jpeg', 0.5);
      box.appendChild(img);
      const badge = document.createElement('div');
      badge.className = 'count-badge';
      badge.textContent = pageCount;
      box.appendChild(badge);
    }

    function updateDoneButton() {
      const btn = document.getElementById('doneBtn');
      const text = document.getElementById('doneBtnText');
      if (pageCount > 0) {
        btn.disabled = false;
        btn.className = 'done-btn active';
        text.textContent = `Done \u00B7 ${pageCount} page${pageCount !== 1 ? 's' : ''}`;
      } else {
        btn.disabled = true;
        btn.className = 'done-btn disabled';
        text.textContent = 'Capture a page to continue';
      }
    }

    /* ── Done Button ── */
    document.getElementById('doneBtn').addEventListener('click', async () => {
      if (pageCount === 0) return;

      showLoading('Finalizing...');

      try {
        await fetch(`${API_BASE}/scan-session/${SESSION_TOKEN}/status`, {
          method: 'PUT',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ status: 'completed' }),
        });

        if (cameraStream) {
          cameraStream.getTracks().forEach(t => t.stop());
        }

        showScreen('complete');
        hideLoading();
      } catch {
        hideLoading();
        alert('Error finalizing session. You may close this tab.');
      }
    });
  </script>
</body>
</html>
