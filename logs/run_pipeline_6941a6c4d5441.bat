@echo off
setlocal enabledelayedexpansion
"C:\xampp\php\php.exe" "C:\xampp\htdocs\movies\scripts\pipeline_runner.php" --episodes="C:\Users\User\Downloads\imdb\imdb\title.episode.tsv" --principals="C:\Users\User\Downloads\imdb\imdb\title.principals.tsv" --names="C:\Users\User\Downloads\imdb\imdb\name.basics.tsv" --log="C:\xampp\htdocs\movies\logs\imdb_pipeline_20251216_193652.log"
