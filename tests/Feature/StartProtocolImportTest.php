<?php

namespace Tests\Feature;

use App\Support\PerformanceApparatus;
use Tests\TestCase;

class StartProtocolImportTest extends TestCase
{
    public function test_bp_and_one_vid_bp_first(): void
    {
        $this->assertSame(
            ['БП', 'Вид 1'],
            PerformanceApparatus::apparatusLabelsFromGroupName('2015 г.р., B, Б.П.; 1 вид'),
        );
        $this->assertSame(
            ['БП', 'Вид 1'],
            PerformanceApparatus::apparatusLabelsFromGroupName('2017 г.р., С, БП; 1 вид'),
        );
    }

    public function test_bp_only_without_vid_count(): void
    {
        $this->assertSame(
            ['БП'],
            PerformanceApparatus::apparatusLabelsFromGroupName('2017 г.р., С, Б.П.'),
        );
    }

    public function test_two_vida_without_bp(): void
    {
        $this->assertSame(
            ['Вид 1', 'Вид 2'],
            PerformanceApparatus::apparatusLabelsFromGroupName('2018 г.р., C, 2 вида'),
        );
    }

    public function test_bp_and_two_vida_bp_first(): void
    {
        $this->assertSame(
            ['БП', 'Вид 1'],
            PerformanceApparatus::apparatusLabelsFromGroupName('2018 г.р., C, Б.П.; 2 вида'),
        );
    }

    public function test_three_vida_with_bp_bp_first(): void
    {
        $this->assertSame(
            ['БП', 'Вид 1', 'Вид 2'],
            PerformanceApparatus::apparatusLabelsFromGroupName('2018 г.р., C, БП; 3 вида'),
        );
    }

    public function test_default_one_vid_without_markers(): void
    {
        $this->assertSame(
            ['Вид 1'],
            PerformanceApparatus::apparatusLabelsFromGroupName('2018 г.р., C'),
        );
    }

    public function test_session_key_normalizes_body_only_label(): void
    {
        $this->assertSame('БП', PerformanceApparatus::sessionKey('Б.П.'));
        $this->assertSame('БП', PerformanceApparatus::sessionKey('БП'));
        $this->assertSame('Скакалка', PerformanceApparatus::sessionKey('Скакалка'));
    }
}
