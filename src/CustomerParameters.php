<?php

namespace GeneroWP\GformConversionApi;

/**
 * @see https://developers.facebook.com/docs/marketing-api/conversions-api/parameters
 */
enum CustomerParameters: string
{
    // case EVENT_NAME = 'event_name';
    case EMAIL = 'em';
    case PHONE = 'ph';
    case FIRST_NAME = 'fn';
    case LAST_NAME = 'ln';
    case GENDER = 'ge';
    case DATE_OF_BIRTH = 'db';
    case CITY = 'ct';
    case STATE = 'st';
    case ZIP_CODE = 'zp';
    case COUNTRY = 'country';
}
