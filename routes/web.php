<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FlowController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\RelationController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/login',[AuthController::class,'show'])->name('login');
Route::post('/login',[AuthController::class,'login'])->name('login.submit');

Route::middleware('auth')->group(function(){
    Route::post('/logout',[AuthController::class,'logout'])->name('logout');
    Route::get('/',[DashboardController::class,'index'])->name('dashboard');
    Route::post('/api/preferencias/tema',[UserController::class,'theme'])->name('preferences.theme');

    Route::get('/processos',[FlowController::class,'index'])->name('flows.index');
    Route::post('/processos',[FlowController::class,'store'])->name('flows.store');
    Route::get('/processos/{id}/editor',[FlowController::class,'editor'])->name('flows.editor');
    Route::post('/processos/{id}/duplicar',[FlowController::class,'duplicate'])->name('flows.duplicate');
    Route::delete('/processos/{id}',[FlowController::class,'destroy'])->name('flows.destroy');

    Route::post('/api/processos/{id}/salvar',[FlowController::class,'save'])->name('flows.save');
    Route::post('/api/processos/{id}/rascunho',[FlowController::class,'saveDraft'])->name('flows.draft.save');
    Route::delete('/api/processos/{id}/rascunho',[FlowController::class,'discardDraft'])->name('flows.draft.discard');
    Route::post('/api/processos/{id}/governanca',[FlowController::class,'transition'])->name('flows.transition');
    Route::post('/api/processos/{id}/comentarios',[FlowController::class,'comment'])->name('flows.comment');
    Route::patch('/api/comentarios/{commentId}',[FlowController::class,'resolveComment'])->name('comments.resolve');
    Route::post('/api/processos/{id}/importar',[FlowController::class,'import'])->name('flows.import');

    Route::get('/projetos',[ProjectController::class,'index'])->name('projects.index');
    Route::post('/projetos',[ProjectController::class,'store'])->name('projects.store');
    Route::get('/projetos/{id}',[ProjectController::class,'show'])->name('projects.show');
    Route::put('/projetos/{id}',[ProjectController::class,'update'])->name('projects.update');
    Route::post('/projetos/{id}/participantes',[ProjectController::class,'members'])->name('projects.members');
    Route::post('/projetos/{id}/fluxos',[ProjectController::class,'assign'])->name('projects.assign');
    Route::post('/projetos/{id}/releases',[ProjectController::class,'release'])->name('projects.release');
    Route::delete('/projetos/{id}',[ProjectController::class,'destroy'])->name('projects.destroy');

    Route::get('/mapa-relacoes/{projectId}',[RelationController::class,'show'])->name('relations.show');
    Route::get('/api/mapa-relacoes/{projectId}',[RelationController::class,'data'])->name('relations.data');

    Route::middleware('admin')->group(function(){
        Route::get('/acessos',[UserController::class,'index'])->name('users.index');
        Route::post('/acessos',[UserController::class,'store'])->name('users.store');
        Route::put('/acessos/{username}',[UserController::class,'update'])->name('users.update');
        Route::delete('/acessos/{username}',[UserController::class,'destroy'])->name('users.destroy');
    });
});
