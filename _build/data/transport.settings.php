<?php
/**
 * Loads system settings into build
 * @var modX $modx
 * @package msprobokassa
 * @subpackage build
 */
$settings = [];

$tmp = [
    /*
    'login' => [
        'xtype' => 'textfield',
        'value' => 'test.dev',
    ],
    'password' => [
        'xtype' => 'text-password',
        'value' => '5hdwIOaLPJqz',
    ],
    */
    'test_mode' => [
        'xtype' => 'combo-boolean',
        'value' => true,
    ],
];

foreach ($tmp as $k => $v) {
    /* @var modSystemSetting $setting */
    $setting = $modx->newObject('modSystemSetting');
    $setting->fromArray(array_merge(
        [
            'key' => 'ms2_payment_paygine_' . $k,
            'namespace' => 'minishop2',
            'area' => 'ms2_payment',
        ], $v
    ), '', true, true);

    $settings[] = $setting;
}

unset($tmp);
return $settings;
