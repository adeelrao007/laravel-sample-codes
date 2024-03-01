<?php

namespace App\Console\Commands;

use App\Http\Controllers\HikCentralController;
use App\Models\UserRegisteredPlate;
use Illuminate\Console\Command;

class VehicleRemove extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vehicle:remove';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'remove vehicle from hikCentral';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $vehicles = UserRegisteredPlate::where('type', 'guest')
                        ->whereNotNull('vehicle_id')
                        ->where('created_at', '<=', date("Y-m-d H:i:s", strtotime("-1 day")))
                        ->get();

        foreach($vehicles as $vehicle) {
            if(HikCentralController::removeVehicle($vehicle->vehicle_id)) {
                //$this->info($vehicle->vehicle_id . ' deleted');
                $vehicle->delete();
            }
        }
    }
}