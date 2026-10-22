"""Admin panel smoke test. Usage: python3 tests/e2e/admin.py <email> <password> [base_url] [screens_dir]"""
import json
import sys

from playwright.sync_api import sync_playwright

EMAIL, PASSWORD = sys.argv[1], sys.argv[2]
BASE = sys.argv[3] if len(sys.argv) > 3 else "http://127.0.0.1:8080"
OUT = sys.argv[4] if len(sys.argv) > 4 else "storage/screens"
errors, failed = [], []

with sync_playwright() as p:
    browser = p.chromium.launch(channel="chrome")
    page = browser.new_page(viewport={"width": 1500, "height": 1000})
    page.on("console", lambda m: errors.append(m.text) if m.type == "error" else None)
    page.on("pageerror", lambda e: errors.append(str(e)))
    page.on("response", lambda r: failed.append(f"{r.status} {r.url}") if r.status >= 500 else None)

    page.goto(BASE + "/admin", wait_until="networkidle")
    assert page.url.endswith("/admin/login"), "unauthenticated admin should redirect to login"
    page.fill("input[name=email]", EMAIL)
    page.fill("input[name=password]", "wrong-password")
    page.click("button[type=submit]")
    page.wait_for_selector(".alert-error")
    page.fill("input[name=email]", EMAIL)
    page.fill("input[name=password]", PASSWORD)
    page.click("button[type=submit]")
    page.wait_for_selector("text=Data coverage")
    page.screenshot(path=f"{OUT}/a1-dashboard.png")
    for path, marker, name in [("/admin/jobs", "Queue backfill", "a2-jobs"), ("/admin/underlyings", "Add underlying", "a3-underlyings"), ("/admin/settings", "Data collection", "a4-settings"), ("/admin/holidays", "Trading holidays", "a5-holidays"), ("/admin/account", "Change password", "a6-account")]:
        page.goto(BASE + path, wait_until="networkidle")
        page.wait_for_selector(f"text={marker}")
        page.screenshot(path=f"{OUT}/{name}.png", full_page=True)
    # settings round trip
    page.goto(BASE + "/admin/settings", wait_until="networkidle")
    page.fill("input[name=strike_band]", "25")
    page.click("button:has-text('Save settings')")
    page.wait_for_selector(".alert-ok")
    # add + remove a holiday
    page.goto(BASE + "/admin/holidays", wait_until="networkidle")
    page.fill("input[name=day]", "2027-01-26")
    page.fill("input[name=description]", "Republic Day")
    page.click("button:has-text('Add')")
    page.wait_for_selector("text=Republic Day")
    row = page.locator("tr", has_text="2027-01-26")
    row.locator("button:has-text('Remove')").click()
    page.wait_for_timeout(500)
    assert page.locator("tr", has_text="2027-01-26").count() == 0
    # logout
    page.click("button:has-text('Sign out')")
    page.wait_for_url("**/admin/login")
    browser.close()

print("console errors:", json.dumps(errors) if errors else "none")
print("server errors:", json.dumps(failed) if failed else "none")
sys.exit(1 if errors or failed else 0)
