<?php

/**
 * OSS
 *
 * @author  Jay Trees <modified-oss@grandels.email>
 * @link    https://github.com/grandeljay/modified-oss
 * @package GrandeljayOss
 */

use Grandeljay\Oss\Constants;

$translations = [
    /** Module */
    'TITLE'            => 'grandeljay - OSS',
    'LONG_DESCRIPTION' => '',
    'STATUS_TITLE'     => 'Status',
    'STATUS_DESC'      => 'Wählen Sie Ja um das Modul zu aktivieren und Nein um es zu deaktivieren.',
    'SORT_ORDER_TITLE' => 'Sortierreihenfolge',
    'SORT_ORDER_DESC'  => 'Anzeigereihenfolge',
];

foreach ($translations as $key => $text) {
    $constant = Constants::MODULE_NAME . '_' . $key;

    define($constant, $text);
}
