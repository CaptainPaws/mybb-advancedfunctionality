<?php
if (!defined('IN_MYBB')) { die('No direct access'); }

/** Presentation metadata for sides, independent of combat elements and relationships. */
final class AF_ACS_SideAccent
{
    public const DEFAULT = 'neutral';
    public const OPTIONS = [
        'neutral'=>'Нейтральный', 'fire'=>'Огонь', 'water'=>'Вода', 'air'=>'Воздух',
        'earth'=>'Земля', 'ice'=>'Лёд', 'lightning'=>'Молния', 'nature'=>'Природа',
        'light'=>'Свет', 'dark'=>'Тьма', 'necrotic'=>'Некроз', 'life'=>'Жизнь',
        'blood'=>'Кровь', 'chaos'=>'Хаос', 'mental'=>'Разум', 'abyss'=>'Бездна',
        'gravity'=>'Гравитация', 'astral'=>'Астрал', 'space'=>'Пространство',
    ];

    public static function requireValid(mixed $accent): string
    {
        if (!is_string($accent) || !isset(self::OPTIONS[$accent])) {
            throw new RuntimeException('ACS_SIDE_ACCENT_INVALID');
        }
        return $accent;
    }

    public static function fromPolicy(?string $json): string
    {
        $policy=json_decode($json??'',false,32);
        $accent=$policy->presentation->accent??null;
        return is_string($accent) && isset(self::OPTIONS[$accent]) ? $accent : self::DEFAULT;
    }
}
