<?php

declare(strict_types=1);

require dirname(__DIR__, 4).'/vendor/autoload.php';

use Storm\Message\Exception\InvalidMessageException;
use Storm\Message\Message;

$value = [];
$value['self'] = &$value;
try {
    ($argv[1] ?? '') === 'constructor'
        ? new Message(new stdClass, ['meta' => $value])
        : new Message(new stdClass)->withHeader('meta', $value);
} catch (InvalidMessageException) {
    fwrite(STDOUT, "gate_refused\n");
    exit(0);
}
exit(1);
