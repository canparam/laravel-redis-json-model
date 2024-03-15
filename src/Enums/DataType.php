<?php

namespace Ken\Enums;

enum DataType: string
{
    use EnumToArray;

    case INT = 'NUMERIC';
    case BOOL = 'boolean';
    case TEXT = 'TEXT';
    case STRING = 'STRING';
    case TAG = 'TAG';
}
