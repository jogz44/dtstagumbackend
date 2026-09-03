<?php

namespace Database\Seeders;

use App\Models\Barangay;
use Illuminate\Database\Seeder;

class BarangaySeeder extends Seeder
{
    public const BARANGAYS = [
        'Apokon',
        'Bincungan',
        'Busaon',
        'Canocotan',
        'Cuambogan',
        'La Filipina',
        'Liboganon',
        'Madaum',
        'Magdum',
        'Magugpo East',
        'Magugpo North',
        'Magugpo Poblacion',
        'Magugpo South',
        'Magugpo West',
        'Mankilam',
        'New Balamban',
        'Nueva Fuerza',
        'Pagsabangan',
        'Pandapan',
        'San Agustin',
        'San Isidro',
        'San Miguel',
        'Visayan Village',
    ];

    public function run(): void
    {
        foreach (self::BARANGAYS as $name) {
            Barangay::firstOrCreate(
                ['name' => $name],
                ['city' => 'Tagum City', 'is_active' => true]
            );
        }
    }
}
