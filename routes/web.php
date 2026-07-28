<?php

use App\Http\Controllers\AthleteMusicController;
use App\Http\Controllers\InquiryController;
use App\Http\Controllers\JudgeController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicAthleteUploadController;
use App\Http\Controllers\ScoreboardController;
use App\Http\Controllers\SecretaryController;
use App\Http\Controllers\SupervisorController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/upload-music', [PublicAthleteUploadController::class, 'index'])
    ->name('public-athlete-upload.index');
Route::post('/upload-music/search', [PublicAthleteUploadController::class, 'search'])
    ->middleware('throttle:10,1')
    ->name('public-athlete-upload.search');
Route::post('/upload-music/{athlete}', [PublicAthleteUploadController::class, 'store'])
    ->middleware('throttle:10,1')
    ->name('public-athlete-upload.store');

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('/athlete/music', [AthleteMusicController::class, 'index'])
        ->middleware('role:athlete')
        ->name('athlete.music');
    Route::post('/athlete/music', [AthleteMusicController::class, 'store'])
        ->middleware('role:athlete')
        ->name('athlete.music.store');
    Route::get('/tracks/{track}/download', [AthleteMusicController::class, 'download'])
        ->name('tracks.download');

    Route::get('/secretary/categories/{category}/queue', [SecretaryController::class, 'queue'])
        ->middleware('role:secretary,chief_judge,admin')
        ->name('secretary.queue');
    Route::get('/secretary/categories/{category}/queue/ping', [SecretaryController::class, 'queuePing'])
        ->middleware('role:secretary,chief_judge,admin')
        ->name('secretary.queue.ping');
    Route::post('/secretary/categories/{category}/queue', [SecretaryController::class, 'addToQueue'])
        ->middleware('role:secretary,chief_judge,admin')
        ->name('secretary.queue.add');
    Route::post('/secretary/categories/{category}/queue-reorder', [SecretaryController::class, 'reorderQueue'])
        ->middleware('role:secretary,chief_judge,admin')
        ->name('secretary.queue.reorder');
    Route::post('/secretary/performances/{performance}/queue-move', [SecretaryController::class, 'moveQueue'])
        ->middleware('role:secretary,chief_judge,admin')
        ->name('secretary.queue.move');
    Route::post('/secretary/performances/{performance}/queue-remove', [SecretaryController::class, 'removeFromQueue'])
        ->middleware('role:secretary,chief_judge,admin')
        ->name('secretary.queue.remove');
    Route::get('/secretary', [SecretaryController::class, 'categories'])
        ->middleware('role:secretary,chief_judge,admin')
        ->name('secretary.categories');
    Route::get('/secretary/tournaments', [SecretaryController::class, 'tournaments'])
        ->middleware('role:secretary,chief_judge,admin')
        ->name('secretary.tournaments');
    Route::post('/secretary/tournaments', [SecretaryController::class, 'storeTournament'])
        ->middleware('role:secretary,chief_judge,admin')
        ->name('secretary.tournaments.store');
    Route::get('/secretary/tournaments/{tournament}', [SecretaryController::class, 'tournament'])
        ->middleware('role:secretary,chief_judge,admin')
        ->name('secretary.tournament');
    Route::get('/secretary/tournaments/{tournament}/live', [SecretaryController::class, 'tournamentLive'])
        ->middleware('role:secretary,chief_judge,admin')
        ->name('secretary.tournament.live');
    Route::get('/secretary/tournaments/{tournament}/protocol', [SecretaryController::class, 'downloadProtocol'])
        ->middleware('role:secretary,chief_judge,admin,organising_committee')
        ->name('secretary.tournament.protocol');
    Route::post('/secretary/tournaments/{tournament}/import-start-protocol', [SecretaryController::class, 'importStartProtocol'])
        ->middleware('role:secretary,chief_judge,admin')
        ->name('secretary.tournament.importStartProtocol');
    Route::get('/secretary/tournaments/{tournament}/groups', [SecretaryController::class, 'groups'])
        ->middleware('role:secretary,chief_judge,admin')
        ->name('secretary.tournament.groups');
    Route::post('/secretary/tournaments/{tournament}/groups', [SecretaryController::class, 'storeGroup'])
        ->middleware('role:secretary,chief_judge,admin')
        ->name('secretary.tournament.groups.store');
    Route::post('/secretary/tournaments/{tournament}/entries', [SecretaryController::class, 'storeEntry'])
        ->middleware('role:secretary,chief_judge,admin')
        ->name('secretary.tournament.entries.store');
    Route::post('/secretary/tournaments/{tournament}/athletes/{athlete}', [SecretaryController::class, 'updateTournamentAthlete'])
        ->middleware('role:secretary,chief_judge,admin')
        ->name('secretary.tournament.athletes.update');
    Route::post('/secretary/tournaments/{tournament}/teams', [SecretaryController::class, 'storeTeam'])
        ->middleware('role:secretary,chief_judge,admin')
        ->name('secretary.tournament.teams.store');
    Route::post('/secretary/teams/{team}', [SecretaryController::class, 'updateTeam'])
        ->middleware('role:secretary,chief_judge,admin')
        ->name('secretary.teams.update');
    Route::post('/secretary/tournaments/{tournament}/assemble', [SecretaryController::class, 'assembleTournament'])
        ->middleware('role:secretary,chief_judge,admin')
        ->name('secretary.tournament.assemble');
    Route::post('/secretary/tournaments/{tournament}/streams-all', [SecretaryController::class, 'generateAllStreams'])
        ->middleware('role:secretary,chief_judge,admin')
        ->name('secretary.tournament.streams.all');
    Route::post('/secretary/tournaments/{tournament}/groups/{group}/streams', [SecretaryController::class, 'generateStreams'])
        ->middleware('role:secretary,chief_judge,admin')
        ->name('secretary.tournament.groups.streams');
    Route::post('/secretary/tournaments/{tournament}/categories/{category}/sessions', [SecretaryController::class, 'storeStreamSession'])
        ->middleware('role:secretary,chief_judge,admin')
        ->name('secretary.tournament.categories.sessions.store');
    Route::patch('/secretary/tournaments/{tournament}/categories/{category}/sessions/{session}', [SecretaryController::class, 'updateStreamSession'])
        ->middleware('role:secretary,chief_judge,admin')
        ->name('secretary.tournament.categories.sessions.update');
    Route::delete('/secretary/tournaments/{tournament}/categories/{category}/sessions/{session}', [SecretaryController::class, 'destroyStreamSession'])
        ->middleware('role:secretary,chief_judge,admin')
        ->name('secretary.tournament.categories.sessions.destroy');
    Route::post('/secretary/tournaments/{tournament}/groups/{group}/apparatus', [SecretaryController::class, 'setGroupApparatus'])
        ->middleware('role:secretary,chief_judge,admin')
        ->name('secretary.tournament.groups.apparatus');
    Route::post('/secretary/tournaments/{tournament}/groups/{group}/renumber', [SecretaryController::class, 'renumberGroup'])
        ->middleware('role:secretary,chief_judge,admin')
        ->name('secretary.tournament.groups.renumber');
    Route::post('/secretary/tournaments/{tournament}/groups/{group}/shuffle', [SecretaryController::class, 'shuffleGroup'])
        ->middleware('role:secretary,chief_judge,admin')
        ->name('secretary.tournament.groups.shuffle');
    Route::delete('/secretary/tournaments/{tournament}/groups/{group}', [SecretaryController::class, 'destroyGroup'])
        ->middleware('role:secretary,chief_judge,admin')
        ->name('secretary.tournament.groups.destroy');
    Route::post('/secretary/entries/{entry}/move', [SecretaryController::class, 'moveEntry'])
        ->middleware('role:secretary,chief_judge,admin')
        ->name('secretary.entries.move');
    Route::post('/secretary/entries/{entry}/reorder', [SecretaryController::class, 'reorderEntry'])
        ->middleware('role:secretary,chief_judge,admin')
        ->name('secretary.entries.reorder');
    Route::post('/secretary/tournaments/{tournament}/categories', [SecretaryController::class, 'storeCategory'])
        ->middleware('role:secretary,chief_judge,admin')
        ->name('secretary.tournament.categories.store');
    Route::delete('/secretary/tournaments/{tournament}/categories', [SecretaryController::class, 'clearTournamentCategories'])
        ->middleware('role:secretary,chief_judge,admin')
        ->name('secretary.tournament.categories.clear');
    Route::delete('/secretary/tournaments/{tournament}/categories/{category}', [SecretaryController::class, 'destroyCategory'])
        ->middleware('role:secretary,chief_judge,admin')
        ->name('secretary.tournament.categories.destroy');
    Route::get('/secretary/athletes', [SecretaryController::class, 'athletes'])
        ->middleware('role:secretary,chief_judge,admin')
        ->name('secretary.athletes');
    Route::post('/secretary/athletes', [SecretaryController::class, 'storeAthlete'])
        ->middleware('role:secretary,chief_judge,admin')
        ->name('secretary.athletes.store');
    Route::post('/secretary/categories/{category}/call-next', [SecretaryController::class, 'callNext'])
        ->middleware('role:secretary,chief_judge,admin')
        ->name('secretary.callNext');
    Route::post('/secretary/categories/{category}/auto-advance', [SecretaryController::class, 'setAutoAdvance'])
        ->middleware('role:secretary,chief_judge,admin')
        ->name('secretary.category.autoAdvance');
    Route::post('/secretary/categories/{category}/judge-slots', [SecretaryController::class, 'toggleJudgeSlot'])
        ->middleware('role:secretary,chief_judge,admin')
        ->name('secretary.category.judgeSlot.toggle');
    Route::post('/secretary/performances/{performance}/start', [SecretaryController::class, 'start'])
        ->middleware('role:secretary,chief_judge,admin')
        ->name('secretary.start');
    Route::post('/secretary/performances/{performance}/finish', [SecretaryController::class, 'finish'])
        ->middleware('role:secretary,chief_judge,admin')
        ->name('secretary.finish');
    Route::post('/secretary/categories/{category}/performance-music', [SecretaryController::class, 'uploadPerformanceMusic'])
        ->middleware('role:secretary,chief_judge,admin')
        ->name('secretary.category.performance.music');
    Route::post('/secretary/performances/{performance}/confirm-score', [SecretaryController::class, 'confirmScore'])
        ->middleware('role:secretary,chief_judge,admin')
        ->name('secretary.performance.confirmScore');
    Route::post('/secretary/performances/{performance}/return-scores', [SecretaryController::class, 'returnScores'])
        ->middleware('role:secretary,chief_judge,admin')
        ->name('secretary.performance.returnScores');
    Route::post('/secretary/performances/{performance}/update-judge-score', [SecretaryController::class, 'updateJudgeScore'])
        ->middleware('role:secretary,chief_judge,admin')
        ->name('secretary.performance.updateJudgeScore');
    Route::post('/secretary/performances/{performance}/set-final-score', [SecretaryController::class, 'setFinalScore'])
        ->middleware('role:secretary,chief_judge,admin')
        ->name('secretary.performance.setFinalScore');
    Route::post('/secretary/performances/{performance}/clear-final-override', [SecretaryController::class, 'clearFinalOverride'])
        ->middleware('role:secretary,chief_judge,admin')
        ->name('secretary.performance.clearFinalOverride');
    Route::post('/secretary/performances/{performance}/withdraw', [SecretaryController::class, 'withdrawPerformance'])
        ->middleware('role:secretary,chief_judge,admin')
        ->name('secretary.performance.withdraw');
    Route::post('/secretary/performances/{performance}/restore', [SecretaryController::class, 'restorePerformance'])
        ->middleware('role:secretary,chief_judge,admin')
        ->name('secretary.performance.restore');

    Route::get('/judge', [JudgeController::class, 'tournaments'])
        ->middleware('role:judge,admin')
        ->name('judge.tournaments');
    Route::get('/judge/tournaments/{tournament}/tablet', [JudgeController::class, 'tournamentTablet'])
        ->middleware('role:judge,admin')
        ->name('judge.tournament.tablet');
    Route::get('/judge/tournaments/{tournament}/tablet/ping', [JudgeController::class, 'tournamentTabletPing'])
        ->middleware('role:judge,admin')
        ->name('judge.tournament.tablet.ping');
    Route::post('/judge/tournaments/{tournament}/tablet/score', [JudgeController::class, 'tournamentTabletSubmit'])
        ->middleware('role:judge,admin')
        ->name('judge.tournament.tablet.score');
    Route::get('/judge/categories/{category}/tablet', [JudgeController::class, 'redirectCategoryTabletToTournament'])
        ->middleware('role:judge,admin')
        ->name('judge.tablet');
    Route::get('/judge/categories/{category}', [JudgeController::class, 'category'])
        ->middleware('role:judge,admin')
        ->name('judge.category');
    Route::post('/judge/performances/{performance}/score', [JudgeController::class, 'submitScore'])
        ->middleware('role:judge,admin')
        ->name('judge.score');
    Route::post('/judge/submit-score', [JudgeController::class, 'submitScoreAjax'])
        ->middleware('role:judge,admin')
        ->name('judge.submit-score');
    Route::post('/judge/performances/{performance}/finalize', [JudgeController::class, 'finalize'])
        ->middleware('role:secretary,chief_judge,admin')
        ->name('judge.finalize');

    Route::post('/supervisor/performances/{performance}/approve', [SupervisorController::class, 'approve'])
        ->middleware('role:superior_jury,head_judge,chief_judge,admin,super_admin')
        ->name('supervisor.approve');
    Route::post('/supervisor/performances/{performance}/publish', [SupervisorController::class, 'publish'])
        ->middleware('role:superior_jury,head_judge,chief_judge,admin,super_admin')
        ->name('supervisor.publish');

    Route::post('/performances/{performance}/inquiries', [InquiryController::class, 'store'])
        ->middleware('role:secretary,superior_jury,head_judge,chief_judge,admin,super_admin')
        ->name('inquiries.store');
    Route::post('/inquiries/{inquiry}/under-review', [InquiryController::class, 'markUnderReview'])
        ->middleware('role:superior_jury,head_judge,chief_judge,admin,super_admin')
        ->name('inquiries.underReview');
    Route::post('/inquiries/{inquiry}/decide', [InquiryController::class, 'decide'])
        ->middleware('role:superior_jury,head_judge,chief_judge,admin,super_admin')
        ->name('inquiries.decide');
});

require __DIR__.'/auth.php';

Route::get('/scoreboard', [ScoreboardController::class, 'index'])
    ->name('scoreboard.index');

Route::get('/scoreboard/categories/{category}', [ScoreboardController::class, 'category'])
    ->name('scoreboard.category');

Route::get('/scoreboard/categories/{category}/table', [ScoreboardController::class, 'table'])
    ->name('scoreboard.table');

Route::get('/scoreboard/categories/{category}/live', [ScoreboardController::class, 'categoryLive'])
    ->name('scoreboard.category.live');

Route::get('/scoreboard/categories/{category}/now', [ScoreboardController::class, 'performance'])
    ->name('scoreboard.performance');

Route::get('/scoreboard/categories/{category}/now/live', [ScoreboardController::class, 'performanceLive'])
    ->name('scoreboard.performance.live');
