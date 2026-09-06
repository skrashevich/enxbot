// Helper for tests/real-game.sh: create / tear down a tiny 2-level game on a
// live Encounter domain through the encx admin API.
//
//   enxsetup create   -> prints  GAMEID=<id>  and  STARTUNIX=<epoch>
//   enxsetup wipe <id> -> deletes all content from the game
//
// Level 1 has two sectors (ENXBOT-L1, ENXBOT-L1B), level 2 one (ENXBOT-L2),
// each level a task and a hint. The game starts ~75 s in the future because the
// new engine refuses a start in the past.
//
// Built by tests/real-game.sh inside the cached encx-cli module (it imports
// github.com/skrashevich/encx-cli/encx); not part of any Go module here.
//
// Env: EN_DOMAIN (default demo.en.cx), EN_LOGIN, EN_PASS.
package main

import (
	"context"
	"fmt"
	"os"
	"strconv"
	"time"

	"github.com/skrashevich/encx-cli/encx"
)

const (
	l1code = "ENXBOT-L1"
	l2code = "ENXBOT-L2"
)

func die(f string, a ...any) { fmt.Fprintf(os.Stderr, "enxsetup: "+f+"\n", a...); os.Exit(1) }

func mustClient(ctx context.Context) *encx.Client {
	dom := os.Getenv("EN_DOMAIN")
	if dom == "" {
		dom = "demo.en.cx"
	}
	login, pass := os.Getenv("EN_LOGIN"), os.Getenv("EN_PASS")
	if login == "" || pass == "" {
		die("EN_LOGIN / EN_PASS not set")
	}
	c := encx.New(dom, encx.WithLang("ru"), encx.WithTimeout(60*time.Second))
	if err := c.LoginComplete(ctx, login, pass); err != nil {
		die("login: %v", err)
	}
	return c
}

func main() {
	if len(os.Args) < 2 {
		die("usage: enxsetup create | wipe <gameId>")
	}
	ctx := context.Background()
	c := mustClient(ctx)

	switch os.Args[1] {
	case "create":
		create(ctx, c)
	case "wipe":
		if len(os.Args) < 3 {
			die("usage: enxsetup wipe <gameId>")
		}
		gid, err := strconv.Atoi(os.Args[2])
		if err != nil {
			die("bad gameId %q", os.Args[2])
		}
		if err := c.AdminWipeGame(ctx, gid, func(s string) { fmt.Fprintln(os.Stderr, "  "+s) }); err != nil {
			die("wipe %d: %v", gid, err)
		}
		fmt.Fprintf(os.Stderr, "wiped game %d\n", gid)
	default:
		die("unknown command %q", os.Args[1])
	}
}

func create(ctx context.Context, c *encx.Client) {
	now := time.Now()
	// The new engine refuses a start in the past, so create just ahead of now
	// and let the caller wait it out.
	start := now.Add(75 * time.Second)
	gid, err := c.AdminCreateGame(ctx, encx.AdminCreateGameParams{
		Title:          fmt.Sprintf("enxbot e2e %s", now.Format("2006-01-02 15:04:05")),
		Description:    "temporary game for enxbot end-to-end test; safe to delete",
		GameType:       1, // team
		StartDateTime:  start.Format(time.RFC3339),
		FinishDateTime: now.Add(6 * time.Hour).Format(time.RFC3339),
	})
	if err != nil {
		die("AdminCreateGame: %v", err)
	}
	fmt.Fprintf(os.Stderr, "created game %d, starts %s\n", gid, start.Format(time.RFC3339))

	if err := c.AdminCreateLevels(ctx, gid, 2); err != nil {
		die("AdminCreateLevels: %v", err)
	}

	for i, code := range []string{l1code, l2code} {
		lvl := i + 1
		if err := c.AdminCreateTask(ctx, gid, lvl, encx.AdminTask{
			Text:      fmt.Sprintf("Уровень %d: введите код %s", lvl, code),
			ReplaceNl: true,
		}); err != nil {
			die("AdminCreateTask L%d: %v", lvl, err)
		}
		if err := c.AdminCreateSector(ctx, gid, lvl, encx.AdminSector{
			Name:    fmt.Sprintf("Сектор %d-A", lvl),
			Answers: []string{code},
		}); err != nil {
			die("AdminCreateSector L%d: %v", lvl, err)
		}
		// Второй сектор на уровне 1 — чтобы прогнать путь секторов (частичное
		// закрытие) против боевого движка.
		if lvl == 1 {
			if err := c.AdminCreateSector(ctx, gid, lvl, encx.AdminSector{
				Name:    "Сектор 1-B",
				Answers: []string{"ENXBOT-L1B"},
			}); err != nil {
				die("AdminCreateSector L1-B: %v", err)
			}
		}
		if err := c.AdminCreateHint(ctx, gid, lvl, encx.AdminHint{
			Text:      fmt.Sprintf("Подсказка уровня %d: код начинается на ENXBOT", lvl),
			ReplaceNl: true,
			Minutes:   1,
		}); err != nil {
			// hints are optional for the test; warn but continue
			fmt.Fprintf(os.Stderr, "  warn: AdminCreateHint L%d: %v\n", lvl, err)
		}
		fmt.Fprintf(os.Stderr, "  level %d: task + sector(%s) ready\n", lvl, code)
	}

	if body, err := c.EnterGame(ctx, gid); err != nil {
		fmt.Fprintf(os.Stderr, "  warn: EnterGame: %v\n", err)
	} else {
		fmt.Fprintf(os.Stderr, "  EnterGame ok: %s\n", body)
	}

	// machine-readable lines for the shell wrapper
	fmt.Printf("GAMEID=%d\n", gid)
	fmt.Printf("STARTUNIX=%d\n", start.Unix())
}
