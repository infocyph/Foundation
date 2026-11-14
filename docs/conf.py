"""Infbyte framework manual, hosted from the Foundation repository."""
from __future__ import annotations

import os
from datetime import date

from pygments.lexers.web import PhpLexer
from sphinx.highlighting import lexers

project = "Infbyte"
author = "Infocyph"
copyright = f"2026–{date.today():%Y}, Infocyph" if date.today().year > 2026 else "2026, Infocyph"
version = os.getenv("READTHEDOCS_VERSION", "4.x-dev")
release = version
language = "en"
root_doc = "index"
extensions = [
    "myst_parser",
    "sphinx.ext.autosectionlabel",
    "sphinx.ext.extlinks",
    "sphinx_copybutton",
    "sphinx_design",
    "sphinxcontrib.phpdomain",
]
autosectionlabel_prefix_document = True
highlight_language = "php"
lexers["php"] = PhpLexer(startinline=True)

# Legacy engineering Markdown remains available in GitHub, and will be migrated
# one verified chapter at a time. This avoids publishing orphan pages.
exclude_patterns = ["_build", "*.md", "**/*.md"]
html_theme = "sphinx_book_theme"
html_title = "Infbyte — PHP Application Framework"
html_theme_options = {
    "repository_url": "https://github.com/infocyph/Foundation",
    "repository_branch": "feature/foundation-3",
    "path_to_docs": "docs",
    "use_repository_button": True,
    "use_issues_button": True,
    "home_page_in_toc": True,
    "show_toc_level": 2,
}
html_static_path = ["_static"]
html_css_files = ["theme.css"]
html_show_sphinx = False
html_last_updated_fmt = "%Y-%m-%d"
extlinks = {"php": ("https://www.php.net/%s", "%s")}
