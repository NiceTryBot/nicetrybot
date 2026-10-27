<?php

namespace App\Enums;

/**
 * Where a one-try pass stands. A fresh pass (no state) allows page loads and one form submission;
 * that submission spends it. A spent pass still lets the visitor load the page that would lift their
 * verdict (e.g. the dashboard after signing in), provisionally, while the edge reports how it went.
 */
enum GymPassState: string
{
    case Spent = 'spent';
    case Provisional = 'provisional';
}
