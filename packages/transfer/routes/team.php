<?php

use Illuminate\Support\Facades\Route;
use NiceTry\Transfer\Http\Controllers\DownloadTransferFile;

/*
| Signed-in pages, under the team: {current_team}/…
*/

Route::livewire('transfer', 'transfer::import')->name('transfer.import');
Route::get('transfer/origins/{origin}/file', DownloadTransferFile::class)->whereNumber('origin')->name('transfer.file');
