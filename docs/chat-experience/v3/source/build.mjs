// Build every Chat v3 board. Run with cwd = the design scratchpad (read-only); outputs go to chat3/b/.
for (const b of ['workspace', 'compose', 'messages', 'oblivion', 'calls', 'family']) await import(`./b-${b}.mjs`);
