<?php

namespace Database\Seeders;

use App\Models\Division;
use App\Models\Office;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class OfficeDivisionSeeder extends Seeder
{
    public function run(): void
    {
        $offices = [
            ['code' => 'CMO', 'name' => "City Mayor's Office (CMO)", 'short_name' => 'CMO', 'divisions' => ['Executive Services', 'Business Permit and Licensing Division (BPLO)', 'City Investment Promotions and Business Enterprise Development Section (CIPBEDS)', 'Research and Management Information System (MIS) Division', 'City Information Office', 'Local Youth Development Office', 'Gender and Development (GAD) Office', 'Sports Development and Management Office', 'Traffic Management Office']],
            ['code' => 'CEO', 'name' => 'Office of the City Engineer (CEO)', 'short_name' => 'CEO', 'divisions' => ['Administrative Services Division (ASD)', 'Building & Industrial Safety Division', 'Construction Division', 'Electrical Installation & Maintenance Division', 'Industrial Plant Division', 'Maintenance Division', 'Motorpool Division']],
            ['code' => 'BAC', 'name' => 'Bids and Awards Committee (BAC)', 'short_name' => 'BAC', 'divisions' => ['BAC Secretariat', 'Procurement Division']],
            ['code' => 'CVMO', 'name' => "City Vice Mayor's Office", 'short_name' => 'CVMO', 'divisions' => ['Office of the Secretary to the Sangguniang Panlungsod', 'Offices of the Sangguniang Panlungsod Members (Councilors)']],
            ['code' => 'CPDO', 'name' => 'City Planning and Development Office (CPDO)', 'short_name' => 'CPDO', 'divisions' => ['Planning Division', 'Development Planning and Project Monitoring Functions']],
            ['code' => 'CTO', 'name' => "City Treasurer's Office (CTO)", 'short_name' => 'CTO', 'divisions' => ['Treasury Operations', 'Cashiering', 'Collection Services', 'Revenue Collection']],
            ['code' => 'ASSESSOR', 'name' => "City Assessor's Office (CAO)", 'short_name' => 'CAO', 'divisions' => ['Real Property Assessment', 'Tax Mapping', 'Records and Assessment Services']],
            ['code' => 'CHRMO', 'name' => 'City Human Resource Management Office (CHRMO)', 'short_name' => 'CHRMO', 'divisions' => ['Recruitment & Selection', 'Training & Development', 'Personnel Records', 'Employee Welfare']],
            ['code' => 'CBO', 'name' => 'City Budget Office (CBO)', 'short_name' => 'CBO', 'divisions' => ['Budget Preparation', 'Budget Monitoring', 'Budget Evaluation']],
            ['code' => 'ACCOUNTING', 'name' => 'City Accounting Office (CAO)', 'short_name' => 'CAO', 'divisions' => ['Accounting Operations', 'Financial Reporting', 'Bookkeeping']],
            ['code' => 'LEGAL', 'name' => 'City Legal Office', 'short_name' => 'CLO', 'divisions' => ['Legal Services', 'Legal Assistance', 'Litigation']],
            ['code' => 'OCCR', 'name' => 'Office of the City Civil Registrar (OCCR)', 'short_name' => 'OCCR', 'divisions' => ['Birth Registration', 'Marriage Registration', 'Death Registration', 'Legal Instruments']],
            ['code' => 'CSWDO', 'name' => 'City Social Welfare and Development Office (CSWDO)', 'short_name' => 'CSWDO', 'divisions' => ['Social Welfare Programs', 'Family & Community Welfare', 'Senior Citizens Affairs', 'Persons with Disability (PWD) Programs']],
            ['code' => 'PESO', 'name' => 'City Public Employment and Services Office (PESO)', 'short_name' => 'PESO', 'divisions' => ['Employment Facilitation', 'Job Matching', 'Special Employment Programs', 'Livelihood Services']],
            ['code' => 'CHLMO', 'name' => 'City Housing and Land Management Office (CHLMO)', 'short_name' => 'CHLMO', 'divisions' => ['Housing Programs', 'Land Management', 'Informal Settlers Program']],
            ['code' => 'CIO', 'name' => 'City Information Office (CIO)', 'short_name' => 'CIO', 'divisions' => ['Public Information', 'Media Relations', 'Publications']],
            ['code' => 'LYDO', 'name' => 'Local Youth Development Office (LYDO)', 'short_name' => 'LYDO', 'divisions' => ['Youth Development Programs', 'SK Coordination']],
            ['code' => 'GAD', 'name' => 'Gender and Development (GAD) Office', 'short_name' => 'GAD', 'divisions' => ['GAD Planning', 'Gender Mainstreaming', "Women's Programs"]],
        ];

        foreach ($offices as $officeData) {
            $office = Office::updateOrCreate(
                ['code' => $officeData['code']],
                [
                    'name' => $officeData['name'],
                    'short_name' => $officeData['short_name'],
                    'is_active' => true,
                ]
            );

            foreach ($officeData['divisions'] as $divisionIndex => $divisionName) {
                Division::updateOrCreate(
                    [
                        'office_id' => $office->id,
                        'code' => Str::upper(Str::limit(Str::slug($divisionName), 42, '')) . '-' . ($divisionIndex + 1),
                    ],
                    [
                        'name' => $divisionName,
                        'short_name' => null,
                        'is_active' => true,
                    ]
                );
            }
        }
    }
}