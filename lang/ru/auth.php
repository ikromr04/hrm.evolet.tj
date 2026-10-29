<?php

declare(strict_types=1);

return [
    'failed' => 'Неверное имя пользователя или пароль.',
    'password' => 'Некорректный пароль.',
    'throttle' => 'Слишком много попыток входа. Пожалуйста, попробуйте ещё раз через :seconds секунд.',

    // The other forms that take a guess at something — App\Support\GuessLimit.
    // The wait comes ready to be read: «через 40 секунд», «через 10 минут».
    'throttle_attempts' => 'Слишком много попыток. Попробуйте ещё раз через :wait.',
    'throttle_letter' => 'Ссылку запрашивали слишком часто. Попробуйте ещё раз через :wait.',
    // The cap over all the letters together, whatever address each was for.
    'throttle_letters' => 'Писем со ссылкой отправлено слишком много. Попробуйте ещё раз через :wait.',
];
