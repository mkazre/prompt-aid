<?php

namespace App\Contracts;

interface GeocodingInterface
{
    /**
     * Straight-line distance in kilometres between two coordinates.
     */
    public function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float;

    /**
     * Estimated travel time in minutes for a given distance.
     */
    public function etaMinutes(float $distanceKm): int;

    /**
     * Turn a free-text address into coordinates.
     *
     * @return array{lat: float, lng: float}|null
     */
    public function geocode(string $address): ?array;
}
