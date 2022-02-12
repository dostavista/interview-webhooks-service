<?php

namespace Borzo;

/**
 * Все логины должны быть в нижнем регистре, иначе робот может не понять, кого и куда назначать.
 */
class GitlabUsers {
    // Backend Team #1
    public const SIDOROV = 'sidorov';
    public const NECHAEV = 'nechaev';
    public const PETROVA = 'petrova';
    public const SMIRNOV = 'smirnov';

    // Backend Team #2
    public const POPOV   = 'popov';
    public const AKAPIEV = 'akapiev';

    // Backend Team #3
    public const KLADENOV   = 'kladenov';
    public const KOTOVA     = 'kotova';
    public const MIRONOV    = 'mironov';
    public const BORODIN    = 'borodin';
    public const ULANOV     = 'ulanov';
    public const POKROVSKIY = 'pokrovskiy';

    // Infrastructure Team
    public const DOROMEEV = 'doromeev';
    public const TROPAREV = 'troparev';
    public const DUBOVCEV = 'dubovcev';
    public const PUGACHEV = 'pugachev';

    // Payments Team
    public const POGORELOV = 'pogorelov';
    public const NEZLOBIN  = 'nezlobin';
    public const LEBEDEV   = 'lebedev';

    // Frontend Team
    public const IVANOV  = 'ivanov';
    public const VOROBEV = 'vorobev';
    public const PAVLOV  = 'pavlov';
    public const ZAHAROV = 'zaharov';

    public const ANDROID_REVIEWERS = [
        self::IVANOV,
        self::VOROBEV,
    ];

    public const IOS_COURIER_REVIEWERS = [
        self::PAVLOV,
        self::ZAHAROV,
    ];

    public const IOS_CLIENT_REVIEWERS = [
        self::IVANOV,
        self::PAVLOV,
    ];

    public const IOS_BASE_REVIEWERS = [
        self::VOROBEV,
        self::ZAHAROV,
    ];
}
