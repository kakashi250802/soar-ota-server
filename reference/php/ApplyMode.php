<?php

namespace App\Ota;

/** When a staged bundle replaces the running one. The byte is part of the signed bytes. */
enum ApplyMode: string
{
    case NextLaunch = 'next_launch';
    case OnResume = 'on_resume';
    case Immediate = 'immediate';

    public function byte(): int
    {
        return match ($this) {
            self::NextLaunch => 0,
            self::OnResume => 1,
            self::Immediate => 2,
        };
    }
}
