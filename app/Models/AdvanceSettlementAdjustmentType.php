<?php

namespace App\Models;

enum AdvanceSettlementAdjustmentType: string
{
    case Reversal = 'reversal';
    case Replacement = 'replacement';
}
