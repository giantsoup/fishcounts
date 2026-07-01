<?php

namespace App\Enums;

enum EnvironmentalSourceType: string
{
    case Moon = 'moon';
    case Tide = 'tide';
    case WaterTemperature = 'water_temperature';
    case Wave = 'wave';
}
