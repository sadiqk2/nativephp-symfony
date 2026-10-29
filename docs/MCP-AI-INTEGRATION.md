# Symfony AI & Native Desktop MCP Integration

This project integrates the **official PHP Model Context Protocol SDK (`mcp/sdk`)**, **Symfony AI (`symfony/ai-bundle`)**, and **NativePHP for Symfony (`sadiqk2/nativephp-symfony-desktop-bundle`)** into a unified desktop application.

---

## What It Enables

1. **Native Desktop Control for AI Models:**
   External AI clients (Claude Desktop, Cursor, IDE agents) gain direct control over the host operating system through Symfony:
   - **`system_notify`**: Push native OS notifications.
   - **`read_clipboard`** / **`write_clipboard`**: Inspect and update system clipboard.
   - **`open_native_window`**: Launch focused desktop preview windows.
   - **`run_symfony_command`**: Execute console commands (`about`, `cache:clear`, etc.).

2. **Live Application Introspection via MCP Resources:**
   - `symfony://app/routes`: Real-time list of application routes, path patterns, and HTTP methods.
   - `symfony://app/status`: Runtime health, PHP version, and Electron environment status.

3. **Standardized Diagnostic Prompts:**
   - `diagnose_symfony_error`: A prompt template guiding LLM agents to inspect runtime errors, propose PHP 8.4 fixes, and notify the user when done.

---

## Quick Start & Verification

### 1. Inspect Tools via Symfony Console
```bash
php bin/console debug:mcp
```

### 2. Connect to Claude Desktop (Free)
Add this to your Claude Desktop configuration file:
* **Windows:** `%APPDATA%\Claude\claude_desktop_config.json`
* **macOS:** `~/Library/Application Support/Claude/claude_desktop_config.json`

```json
{
  "mcpServers": {
    "symfony-desktop": {
      "command": "php",
      "args": [
        "C:/Users/sadiq/.gemini/antigravity/scratch/nativephp-symfony/demo/bin/console",
        "mcp:server"
      ]
    }
  }
}
```

### 3. Test with the Official MCP Inspector
You can test the server interactively in your browser with zero setup:
```bash
npx @modelcontextprotocol/inspector php bin/console mcp:server
```

---

## 90-Second Hackathon Demo Script

1. **0:00 - 0:20 (The Setup):**
   - Open the **Deskpad** native desktop app.
   - Click on the new **AI & MCP** tab in the navigation bar to display registered tools and server status.
2. **0:20 - 0:50 (External Agent Interaction):**
   - Open **Claude Desktop** side-by-side with Deskpad.
   - Ask Claude: *"Run a diagnostic check on my Symfony application and notify me on the desktop when completed."*
3. **0:50 - 1:15 (The Native Execution):**
   - Claude invokes `run_symfony_command` (inspects `about` and `symfony://app/routes`).
   - Claude calls `write_clipboard` with the summary.
   - Claude calls `system_notify` $\rightarrow$ a real desktop notification appears on Windows/macOS.
4. **1:15 - 1:30 (The Code):**
   - Show `src/Mcp/DesktopTools.php` showcasing modern PHP 8 attributes: `#[McpTool]`, `#[McpResource]`, `#[McpPrompt]`.
