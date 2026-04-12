<?php

namespace App\Enums;

enum SourceType: string
{
    case GitHub = 'github';
    case Jira = 'jira';
}
