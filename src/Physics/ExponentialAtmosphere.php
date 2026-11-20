<?php

declare(strict_types=1);

namespace Apollo\Physics;

/** An atmosphere whose density falls off exponentially with altitude: rho = rho0 * exp(-h / H). */
final readonly class ExponentialAtmosphere
{
    /**
     * @param float $surfaceDensity rho0 in kg/m^3
     * @param float $scaleHeight H in metres
     */
    public function __construct(
        public float $surfaceDensity,
        public float $scaleHeight,
    ) {
    }

    /** Density in kg/m^3 at the given altitude above the surface (below the surface it is the surface density). */
    public function density(float $altitude): float
    {
        return $this->surfaceDensity * exp(-max(0.0, $altitude) / $this->scaleHeight);
    }
}
