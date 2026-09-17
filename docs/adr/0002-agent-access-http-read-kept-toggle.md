# Agent access over HTTP only, read plus kept-toggle

Remote agents get HTTP read access plus the kept toggle on the existing API seam with per-agent per-user keys, and no MCP server this pass, because full write parity and a second MCP-to-DB path add auth risk and maintenance without a concrete client demanding them.
