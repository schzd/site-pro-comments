<?php

// Fixed timeline. 'tie_*' rows share a timestamp to pin the id tie-break.
return [
    'oldest' => [
        'id' => 1, 'author_name' => 'Ada', 'body' => 'First!', 'status' => 10, 'is_demo' => 0,
        'likes' => 0, 'ip' => '10.0.0.1', 'created_at' => 1700000000, 'updated_at' => 1700000000,
    ],
    'hidden' => [
        'id' => 2, 'author_name' => 'Troll', 'body' => 'spam spam', 'status' => 0, 'is_demo' => 0,
        'likes' => 0, 'ip' => '10.0.0.2', 'created_at' => 1700000100, 'updated_at' => 1700000100,
    ],
    'tie_a' => [
        'id' => 3, 'author_name' => 'Bob', 'body' => 'Same second A', 'status' => 10, 'is_demo' => 0,
        'likes' => 2, 'ip' => '10.0.0.3', 'created_at' => 1700000200, 'updated_at' => 1700000200,
    ],
    'tie_b' => [
        'id' => 4, 'author_name' => 'Cleo', 'body' => 'Same second B', 'status' => 10, 'is_demo' => 0,
        'likes' => 0, 'ip' => '10.0.0.4', 'created_at' => 1700000200, 'updated_at' => 1700000200,
    ],
    'demo' => [
        'id' => 5, 'author_name' => 'Demo Bot', 'body' => 'Simulated traffic', 'status' => 10, 'is_demo' => 1,
        'likes' => 0, 'ip' => '10.0.0.5', 'created_at' => 1700000300, 'updated_at' => 1700000300,
    ],
];
