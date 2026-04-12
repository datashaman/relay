<?php

use App\Http\Controllers\OauthController;
use App\Http\Controllers\SourceController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/sources', [SourceController::class, 'index'])->name('sources.index');
Route::post('/sources/{source}/test', [SourceController::class, 'testConnection'])->name('sources.test');
Route::post('/sources/{source}/sync', [SourceController::class, 'syncNow'])->name('sources.sync');

Route::get('/oauth/redirect/{provider}', [OauthController::class, 'redirect'])
    ->name('oauth.redirect')
    ->whereIn('provider', ['github', 'jira']);

Route::get('/oauth/callback/{provider}', [OauthController::class, 'callback'])
    ->name('oauth.callback')
    ->whereIn('provider', ['github', 'jira']);

Route::delete('/oauth/disconnect/{provider}', [OauthController::class, 'disconnect'])
    ->name('oauth.disconnect')
    ->whereIn('provider', ['github', 'jira']);

Route::get('/jira/sites', [OauthController::class, 'jiraSites'])
    ->name('jira.sites');

Route::get('/jira/select-site', [OauthController::class, 'jiraSiteSelectionForm'])
    ->name('jira.select-site.form');

Route::post('/jira/select-site', [OauthController::class, 'jiraSelectSite'])
    ->name('jira.select-site');
