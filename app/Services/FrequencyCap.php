<?php

namespace App\Services;

class FrequencyCap
{
    public function __construct($max = 3, $windowSeconds = 3600)
    {
    }

    /** @return bool true - показ разрешён и засчитан */
    public function allow($userId, $campaignId)
    {
        throw new \RuntimeException('TODO: задача 3, условия в tests/Feature/FrequencyCapTest.php');
    }
}
