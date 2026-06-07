# Console Log Format Cleanup

**Date:** 2026-03-29

## Problem

Console output from Monolog includes noise that makes it harder to scan during optimization runs:
- `INFO` / level indicator on every line (not useful for info-level messages)
- `[app]` / `[php]` channel names
- `["process_id" => 38729]` appended to every line (from `ProcessIdProcessor` injecting into `extra`)

Example before:
```
23:04:30 INFO      [app] Period: 2024-09-01 → 2026-03-01 ["process_id" => 38729]
23:04:30 INFO      [app] Loading candles for DOGEUSDT ... ["process_id" => 38729]
```

## Goal

Clean console output that retains severity signal (level) but drops channel and process_id:
```
23:04:30 INFO Period: 2024-09-01 → 2026-03-01
23:04:30 INFO Loading candles for DOGEUSDT ...
23:04:30 WARNING Skipping dispatch: lock unavailable.
```

## Solution

Two config-only changes. No PHP code required.

### 1. `app/config/services.yaml`

Add a console-specific `LineFormatter` alongside the existing `pid_line_formatter`:

```yaml
monolog.formatter.console_line_formatter:
    class: Monolog\Formatter\LineFormatter
    arguments:
        $format: "%%datetime%% %%level_name%% %%message%% %%context%%\n"
        $dateFormat: "H:i:s"
        $ignoreEmptyContextAndExtra: true
```

- `%%context%%` with `ignoreEmptyContextAndExtra: true` — shows structured context when present, suppresses `[]` when empty
- No `%%extra%%` — drops `process_id` (which lives in `extra`, injected by `ProcessIdProcessor`)
- `%%level_name%%` — severity signal; Monolog uses token substitution not sprintf so fixed-width padding is not available without a custom PHP class

### 2. `app/config/packages/monolog.yaml`

Wire the formatter to the `console` handler:

```yaml
console:
  type: console
  process_psr_3_messages: true
  channels: ["!event", "!doctrine"]
  verbosity_levels:
    VERBOSITY_NORMAL: INFO
    VERBOSITY_VERBOSE: DEBUG
  formatter: monolog.formatter.console_line_formatter
```

## Scope

- **Console handler only** — file handlers (`app`, `all`, `error`) are unaffected and retain the PID format
- **No PHP changes** — config only
- **ProcessIdProcessor stays** — still useful for file log correlation across processes

## Verification

Run any optimization command and confirm:
```bash
docker exec -it paybis-app php -d xdebug.mode=off bin/console app:cmaes-optimize --sim-start-date=2024-09-01 --sim-end-date=2026-03-01 --windows=3
```

Expected: lines like `23:04:30 INFO Period: ...` with no `[app]`, no `["process_id" => ...]`.
