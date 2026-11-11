<?php

use Illuminate\Support\Facades\Schedule;

// Keep the personal_access_tokens table tidy.
Schedule::command('sanctum:prune-expired --hours=24')->daily();
