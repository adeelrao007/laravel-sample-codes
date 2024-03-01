<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Packages as PackagesModel;
use App\Models\PackageDevices;

class Packages extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        PackagesModel::updateOrCreate([
            'name' => 'Bronze',
        ]);
        PackagesModel::updateOrCreate([
            'name' => 'Silver',
        ]);
        PackagesModel::updateOrCreate([
            'name' => 'Gold',
        ]);
        PackagesModel::updateOrCreate([
            'name' => 'Fitness',
        ]);
        PackagesModel::updateOrCreate([
            'name' => 'Coworking',
        ]);
        PackagesModel::updateOrCreate([
            'name' => 'Staff',
        ]);
    }
}
