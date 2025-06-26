<?php

declare(strict_types=1);

arch('it does not use debugging functions')
    ->expect(['dd', 'dump', 'var_dump', 'print_r', 'var_export', 'die', 'exit'])
    ->not->toBeUsed();

arch('it uses strict types')
    ->expect('Whatsdiff')
    ->toUseStrictTypes();

arch('services are in the Services namespace')
    ->expect('Whatsdiff\Services')
    ->toBeClasses();

arch('commands extend Symfony Command')
    ->expect('Whatsdiff\Commands')
    ->toExtend('Symfony\Component\Console\Command\Command');

arch('data classes are readonly')
    ->expect('Whatsdiff\Data')
    ->classes()
    ->toBeReadonly();

arch('enums are in the Enums namespace')
    ->expect('Whatsdiff\Enums')
    ->toBeEnums();

arch('it does not use deprecated PHP functions')
    ->expect(['create_function', 'each', 'ereg', 'eregi', 'mysql_connect'])
    ->not->toBeUsed();

arch('services do not depend on controllers')
    ->expect('Whatsdiff\Services')
    ->not->toUse('Whatsdiff\Controllers');

arch('it uses proper exception handling')
    ->expect('Whatsdiff')
    ->classes()
    ->not->toUse(['trigger_error', 'user_error']);

arch('interfaces follow naming conventions')
    ->expect('Whatsdiff')
    ->interfaces()
    ->toHaveSuffix('Interface');

arch('data classes are readonly and final')
    ->expect('Whatsdiff\Data')
    ->classes()
    ->toBeReadonly()
    ->toBeFinal()
    ->ignoring('Whatsdiff\Data\ChangeStatus');
