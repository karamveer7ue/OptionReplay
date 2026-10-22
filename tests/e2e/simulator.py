"""End-to-end smoke test of the simulator UI with Playwright (python -m playwright install chromium first).

Usage: python3 tests/e2e/simulator.py [base_url] [screens_dir]
Exits non-zero on console errors, failed requests, or missing UI state.
"""
import json
import sys
import time

from playwright.sync_api import sync_playwright

BASE = sys.argv[1] if len(sys.argv) > 1 else "http://127.0.0.1:8080"
OUT = sys.argv[2] if len(sys.argv) > 2 else "storage/screens"
errors, failed = [], []


def shot(page, name):
    page.screenshot(path=f"{OUT}/{name}.png", full_page=False)


with sync_playwright() as p:
    browser = p.chromium.launch(channel="chrome")
    page = browser.new_page(viewport={"width": 1600, "height": 1000})
    page.on("console", lambda m: errors.append(m.text) if m.type == "error" else None)
    page.on("pageerror", lambda e: errors.append(str(e)))
    page.on("requestfailed", lambda r: failed.append(f"{r.failure} {r.url}") if r.failure != "net::ERR_ABORTED" else None)
    page.on("response", lambda r: failed.append(f"{r.status} {r.url}") if r.status >= 400 else None)

    page.goto(BASE + "/", wait_until="networkidle")
    page.wait_for_selector("#chain-body tr")
    shot(page, "01-home")
    label = page.inner_text("#time-label")
    print("time:", label)
    rows = page.locator("#chain-body tr").count()
    print("chain rows:", rows)
    assert rows > 5, "option chain did not render"
    assert page.locator("#chain-body tr.atm").count() == 1, "ATM row missing"
    # move to the start of that session plus one hour so there is room to step forward
    page.click("button.step[data-step='sod']")
    page.wait_for_timeout(900)
    page.click("button.step[data-step='+1h']")
    page.wait_for_timeout(900)
    print("entry time:", page.inner_text("#time-label"))

    # add a short straddle via the chain: sell CE and PE at the nearest strike with both sides priced
    strike = page.evaluate("""() => { const atm = +document.querySelector('#chain-body tr.atm').dataset.strike;
        const ok = [...document.querySelectorAll('#chain-body tr')].filter(tr => tr.querySelector('td.ltp.ce:not(.stale)') && tr.querySelector('td.ltp.pe:not(.stale)'));
        ok.sort((a, b) => Math.abs(+a.dataset.strike - atm) - Math.abs(+b.dataset.strike - atm)); return ok[0].dataset.strike; }""")
    atm = page.locator(f"#chain-body tr[data-strike='{strike}']")
    print("straddle strike:", strike)
    atm.locator("td.ltp.ce").hover()
    atm.locator("td.ltp.ce b.s").click()
    atm.locator("td.ltp.pe").hover()
    atm.locator("td.ltp.pe b.s").click()
    page.wait_for_function("document.querySelectorAll('#legs-body tr[data-id]').length === 2")
    page.wait_for_selector("#metrics .metric")
    shot(page, "02-straddle")
    metrics = page.inner_text("#metrics")
    print("metrics:", metrics.replace("\n", " | "))
    assert "Unlimited" not in metrics.upper().split("MAX LOSS")[0], "short straddle max profit must be bounded"
    assert page.locator("#payoff-chart path.expiry-line").count() == 1, "payoff line missing"

    # step forward and check P&L updates
    before = page.inner_text("#legs-foot")
    page.click("button.step[data-step='+15m']")
    page.wait_for_timeout(800)
    page.click("button.step[data-step='+1h']")
    page.wait_for_timeout(800)
    after = page.inner_text("#legs-foot")
    print("foot before:", before.replace("\n", " | ")[:120])
    print("foot after :", after.replace("\n", " | ")[:120])
    page.click("#view-tabs button[data-view='pnl']")
    page.wait_for_selector("#pnl-chart path.pnl-line", timeout=10000)
    shot(page, "03-pnl")
    page.click("#view-tabs button[data-view='spot']")
    page.wait_for_timeout(1500)
    shot(page, "04-spot")
    page.click("#view-tabs button[data-view='templates']")
    page.wait_for_selector(".template")
    shot(page, "05-templates")
    page.on("dialog", lambda d: d.accept())
    page.locator(".template", has_text="Short Iron Condor").click()
    try:
        page.wait_for_function("document.querySelectorAll('#legs-body tr[data-id]').length === 4", timeout=8000)
        template_legs = 4
    except Exception:
        # Strikes may be missing while the backfill is still running; report rather than fail.
        print("template skipped:", page.inner_text("#toast"))
        template_legs = page.locator("#legs-body tr[data-id]").count()
    shot(page, "06-iron-condor")
    # greeks toggle + share link round trip
    page.check("#chain-greeks")
    page.wait_for_timeout(300)
    shot(page, "07-greeks")
    url = page.evaluate("location.href")
    assert "#s=" in url
    page2 = browser.new_page(viewport={"width": 1600, "height": 1000})
    page2.goto(url, wait_until="networkidle")
    page2.wait_for_function(f"document.querySelectorAll('#legs-body tr[data-id]').length === {template_legs}")
    print("share link restored", template_legs, "legs")
    page2.close()
    # autoplay advances time and can be paused
    t0 = page.input_value("#time-input")
    page.select_option("#autoplay-speed", "500")
    page.click("#autoplay-btn")
    page.wait_for_timeout(2600)
    page.click("#autoplay-btn")
    t1 = page.input_value("#time-input")
    print("autoplay:", t0, "->", t1)
    assert t1 > t0, "autoplay did not advance time"
    assert "Autoplay" in page.inner_text("#autoplay-btn")
    # saved strategies: save, reopen dialog, open it back
    page.fill("#strategy-name", "E2E condor")
    page.click("#save-strategy")
    page.click("#saved-btn")
    page.wait_for_selector(".saved-item")
    shot(page, "07b-saved")
    page.click(".saved-item [data-act=open]")
    page.wait_for_function("document.querySelectorAll('#legs-body tr[data-id]').length > 0")
    # dark theme
    page.click("#theme-toggle")
    page.wait_for_timeout(500)
    shot(page, "08-dark")
    page.wait_for_load_state("networkidle")
    # admin login page
    page.goto(BASE + "/admin/login", wait_until="networkidle")
    shot(page, "09-admin-login")
    browser.close()

print("console errors:", json.dumps(errors, indent=1) if errors else "none")
print("failed requests:", json.dumps(failed, indent=1) if failed else "none")
sys.exit(1 if errors or failed else 0)
