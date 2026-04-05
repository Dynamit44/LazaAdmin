@echo off
cd /d G:\OSPanel\home\lazacup.local

set /p msg=Комментарий коммита:

git add -A
git commit -m "%msg%"
git push

pause