<?php

namespace Ken\Enums;

enum ProtocolType: string
{
    case PREDIS = 'predis';
    case PHP_REDIS = 'phpredis';
}
