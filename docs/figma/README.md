# Figma mockup: Dashboard Admin

`admin-dashboard-mockup.html` and `dataset-dashboard-mockup.html` are standalone HTML/CSS sources based on the current admin dashboard and Datasets UI. They have no Laravel, Vite, database, or external asset dependency, so they can be opened in a browser before importing them into Figma.

The values and names in the mockup are sample UI data. They are not production metrics.

## Import into Figma

1. Install and run an HTML-to-Figma plugin such as html.to.design.
2. Start a temporary static server from the project root:

   ```powershell
   php -S 127.0.0.1:8000 -t docs/figma
   ```

3. Import one of these URLs in the plugin:

   - Admin dashboard: `http://127.0.0.1:8000/admin-dashboard-mockup.html`
   - Datasets: `http://127.0.0.1:8000/dataset-dashboard-mockup.html`
4. Use a `1440 x 1188` desktop frame and keep the default scale at `100%`.
5. The main regions have `data-figma-name` attributes for easier layer naming after import.

Figma does not import Blade or Laravel files directly. The HTML is the portable handoff source for the Figma plugin. If the plugin cannot access a local URL, open the file through a static server as shown above.
