# Git Notes (Windows + PowerShell)

Универсальная инструкция по работе только с `git` в Windows/PowerShell.

## Частые проблемы и обходы

### 1) `git commit` падает с `unknown option 'trailer'`

Использовать commit через явный путь к `git.exe`:

```powershell
& "C:\Program Files\Git\cmd\git.exe" commit -m "Message"
```

### 2) Bash heredoc не работает в PowerShell

Конструкция `cat <<'EOF'` в PowerShell не поддерживается.
Для многострочного сообщения используйте here-string:

```powershell
$msg = @"
Title line

Body line 1
Body line 2
"@
& "C:\Program Files\Git\cmd\git.exe" commit -m "$msg"
```

### 3) Предупреждение про окончания строк (LF/CRLF)

Во время `git add`/`git commit` может появляться:

```powershell
warning: LF will be replaced by CRLF in <file>.
The file will have its original line endings in your working directory
```

Это предупреждение, а не ошибка. Коммит не блокируется.

Если нужна единая политика line endings, добавьте `.gitattributes`, например:

```gitattributes
* text=auto
```

## Базовый workflow Git

### Инициализация нового репозитория

```powershell
git init
git add .
& "C:\Program Files\Git\cmd\git.exe" commit -m "Initial commit"
git branch -M main
```

### Обычный цикл изменений

```powershell
git status -sb
git add <files>
& "C:\Program Files\Git\cmd\git.exe" commit -m "Short message"
git push
```

### Первый push в удаленный репозиторий (если upstream еще не задан)

```powershell
git push -u origin main
```

## Проверки перед push

```powershell
git status -sb
git diff
git diff --staged
git log --oneline -n 5
```

## Быстрый SOP (универсальный)

```powershell
# 0) Убедиться, что вы в корне проекта
ls

# 1) Проверить, git-репозиторий ли это
git rev-parse --is-inside-work-tree

# 2) Если НЕ репозиторий: инициализировать
git init
git add .
& "C:\Program Files\Git\cmd\git.exe" commit -m "Initial commit"
git branch -M main

# 3) Если репозиторий уже есть: обычный цикл
git status -sb
git add <files>
& "C:\Program Files\Git\cmd\git.exe" commit -m "Message"
git push

# 4) Финальная проверка
git status -sb
```
