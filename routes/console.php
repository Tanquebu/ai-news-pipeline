<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('clusters:archive')->daily();
// Un item che si aggancia a un cluster esistente (ClusterNewsItemJob) non
// ritrigghera mai la synthesis: senza un rescore periodico, consensus_count
// resta fuori dal total_score finché non si lancia il comando a mano. Nessuna
// chiamata LLM in ScoringService::updateScore, solo query DB — costo trascurabile.
Schedule::command('clusters:rescore')->dailyAt('00:15');
Schedule::command('dossiers:consolidate')->dailyAt('03:30');
// Dopo il consolidamento: score e candidatura calcolati sui membri aggiornati.
Schedule::command('dossiers:score')->dailyAt('03:45');
// Brief settimanali la domenica mattina: dopo consolidate (03:30) e score
// (03:45) dello stesso giorno, così la selezione usa membership e score
// freschi; in mattinata perché la review umana dei brief avviene nel blocco
// editoriale della domenica (loop settimanale del workspace).
Schedule::command('briefs:generate')->weeklyOn(0, '05:00');
