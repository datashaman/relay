<?php

use App\Http\Controllers\EscalationRuleController;
use App\Http\Controllers\IssueController;
use App\Http\Controllers\OauthController;
use App\Http\Controllers\PreflightController;
use App\Http\Controllers\SourceController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/sources', [SourceController::class, 'index'])->name('sources.index');
Route::post('/sources/{source}/test', [SourceController::class, 'testConnection'])->name('sources.test');
Route::post('/sources/{source}/sync', [SourceController::class, 'syncNow'])->name('sources.sync');

Route::get('/issues/queue', [IssueController::class, 'queue'])->name('issues.queue');
Route::post('/issues/{issue}/accept', [IssueController::class, 'accept'])->name('issues.accept');
Route::post('/issues/{issue}/reject', [IssueController::class, 'reject'])->name('issues.reject');
Route::post('/sources/{source}/toggle-pause', [IssueController::class, 'togglePause'])->name('issues.toggle-pause');

Route::get('/runs/{run}/preflight', [PreflightController::class, 'show'])->name('preflight.show');
Route::post('/runs/{run}/preflight/answers', [PreflightController::class, 'submitAnswers'])->name('preflight.submit-answers');
Route::post('/runs/{run}/preflight/skip', [PreflightController::class, 'skipToDoc'])->name('preflight.skip');

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

Route::get('/escalation-rules', [EscalationRuleController::class, 'index'])->name('escalation-rules.index');
Route::get('/escalation-rules/create', [EscalationRuleController::class, 'create'])->name('escalation-rules.create');
Route::post('/escalation-rules', [EscalationRuleController::class, 'store'])->name('escalation-rules.store');
Route::get('/escalation-rules/{escalationRule}/edit', [EscalationRuleController::class, 'edit'])->name('escalation-rules.edit');
Route::put('/escalation-rules/{escalationRule}', [EscalationRuleController::class, 'update'])->name('escalation-rules.update');
Route::delete('/escalation-rules/{escalationRule}', [EscalationRuleController::class, 'destroy'])->name('escalation-rules.destroy');
Route::post('/escalation-rules/{escalationRule}/toggle', [EscalationRuleController::class, 'toggleEnabled'])->name('escalation-rules.toggle');
Route::post('/escalation-rules/{escalationRule}/move-up', [EscalationRuleController::class, 'moveUp'])->name('escalation-rules.move-up');
Route::post('/escalation-rules/{escalationRule}/move-down', [EscalationRuleController::class, 'moveDown'])->name('escalation-rules.move-down');
Route::post('/escalation-rules/reorder', [EscalationRuleController::class, 'reorder'])->name('escalation-rules.reorder');
