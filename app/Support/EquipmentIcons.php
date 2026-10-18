<?php

namespace App\Support;

/**
 * The drawings a category of hardware can be shown by. The keys are stored on
 * the category and the interface knows how to draw each one, so the two lists
 * have to agree — which is why the server keeps this one and validates against
 * it rather than taking whatever a form sends.
 */
class EquipmentIcons
{
    /** @var list<string> */
    public const KEYS = [
        'laptop',
        'monitor',
        'smartphone',
        'tablet',
        'keyboard',
        'mouse',
        'headphones',
        'printer',
        'scanner',
        'projector',
        'camera',
        'server',
        'harddrive',
        'router',
        'speaker',
        'watch',
        'cable',
        'battery',
        'chair',
        'desk',
        'shelves',
        'cabinet',
        'picture',
        'conditioner',
        'extinguisher',
        'package',
    ];
}
