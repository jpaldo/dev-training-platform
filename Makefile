.PHONY: start dev stop restart status

start: dev

dev:
	@powershell.exe -NoProfile -ExecutionPolicy Bypass -File "$(CURDIR)/scripts/dev.ps1" -Action start

stop:
	@powershell.exe -NoProfile -ExecutionPolicy Bypass -File "$(CURDIR)/scripts/dev.ps1" -Action stop

restart:
	@powershell.exe -NoProfile -ExecutionPolicy Bypass -File "$(CURDIR)/scripts/dev.ps1" -Action restart

status:
	@powershell.exe -NoProfile -ExecutionPolicy Bypass -File "$(CURDIR)/scripts/dev.ps1" -Action status
