<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default PDF Converter
    |--------------------------------------------------------------------------
    |
    | Specifies the default converter version to use. This can be any of the
    | keys defined in the 'converters' array below.
    |
    */
    'default_converter' => env('PDF_CONVERTER_VERSION', 'v10'),

    /*
    |--------------------------------------------------------------------------
    | Available PDF Converters
    |--------------------------------------------------------------------------
    |
    | A list of all available PyMuPDF converter scripts. The key is the
    | version identifier, and the value is the full path to the script.
    | The scripts will be tried in the order they are listed here when
    | a specific version is not found or fails.
    |
    */
    'converters' => [
        'v11' => resource_path('scripts/python/pymupdf_converter_v11.py'),
        'v10' => resource_path('scripts/python/pymupdf_converter_v10.py'),
        'v7' => resource_path('scripts/python/pymupdf_converter_v7.py'),
        'v2' => resource_path('scripts/python/pymupdf_converter_v2.py'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Fallback Chain
    |--------------------------------------------------------------------------
    |
    | Defines the order in which to try converters if the requested or
    | default converter fails. This provides a resilient fallback mechanism.
    | The list should contain the keys from the 'converters' array.
    |
    */
    'fallback_chain' => [
        'v11',
        'v10',
        'v7',
        'v2',
    ],
];
