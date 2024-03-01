<?php

namespace App\Helpers;

use App\Models\Address;
use App\Services\Google\PlacesService;
use App\Services\LocationService;
use Exception;

class CommonHelper
{
    /*
     * Get Location from Ip address
     *
     * @param Request $request
     * @return ip location
     * @throws Exception
     * @todo Return ip location
     */
    public static function getIpGeolocation()
    {
        try {
            $url = 'https://'.config('environments.deadstock_cloud_function_host').'/ip-to-city';
            $result = (new LocationService)->makeGetRequest($url);
            $latlong = $result['citylatlong'];
            $loc = explode(',', $latlong);
            $geo = [];
            $geo['lat'] = $loc[0];
            $geo['lon'] = $loc[1];

            return $geo;
        } catch (Exception $exception) {
            report($exception);
        }
    }

    /**
     * calculate distance between 2 lat & long.
     *
     * @return string
     */
    public static function calculateDistanceBetweenTwoLatLang($lat1, $long1, $lat2, $long2)
    {
        $delta = $long1 - $long2;
        $distance = sin(deg2rad($lat1)) * sin(deg2rad($lat2)) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($delta));

        $distance = acos($distance);
        $distance = rad2deg($distance);
        $distance = $distance * 60 * 1.1515 * 1.609344; //km

        return round($distance, 2);
    }

    /**
     * Get nearest warehouse for location to get shipment.
     *
     * @return string
     */
    public static function getNearestwarehouseForAddress($fromPoints)
    {
        $wareHouse = (new Address())->getNearestWareHouseForLatLang($fromPoints['lat'], $fromPoints['lon']);

        return reset($wareHouse);
    }

    /**
     * Get Address Lat & Long from Googl places.
     *
     * @param string $address
     * @return array
     */
    public static function googlePlacesAddressLatLong($address)
    {
        try {
            $result = (new PlacesService)->makeGetRequest(
                config('constants.google.geocode_url'),
                [
                    'address' => $address,
                    'key' => config('constants.google.maps_api_key'),
                ]
            );

            $response['lat'] = $result['results'][0]['geometry']['location']['lat'];
            $response['lon'] = $result['results'][0]['geometry']['location']['lng'];

            return $response;
        } catch (Exception $exception) {
            report($exception);
        }
    }
}
