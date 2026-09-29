#!/usr/bin/env python3
"""
Bar charts for swerve's README, as SVG in a light and a dark variant, from charts.json.

    python3 benchmarks/charts/make.py          # writes benchmarks/charts/<name>-light.svg, -dark.svg

A chart is a list of groups (one group without a label for a plain chart), each a list of bars:
{"label": "swerve", "value": 450522, "kind": "swerve"|"swerve-ext"|"other", "note": "‡"}.
Values are drawn to one linear scale from zero per chart. The README embeds both variants with
<picture>, so GitHub shows the one that matches the reader's theme.
"""
import json
import os
from html import escape

HERE = os.path.dirname(os.path.abspath(__file__))

THEMES = {
    'light': {
        'text': '#0D1B2E', 'muted': '#56677D', 'grid': '#D9E1EA', 'axis': '#9FB0C2',
        'swerve': '#2F6FAE', 'swerve-ext': '#5B93CC', 'other': '#B6C2CF', 'value-on': '#FFFFFF',
    },
    'dark': {
        'text': '#E6EEF8', 'muted': '#93A4B8', 'grid': '#243246', 'axis': '#3D4F66',
        'swerve': '#8FB5E0', 'swerve-ext': '#5E8DC2', 'other': '#46586E', 'value-on': '#0B1A2E',
    },
}

FONT = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif"
WIDTH = 760
LABEL_W = 170     # server names
VALUE_W = 110     # room for the value after the longest bar
BAR_H = 22
BAR_GAP = 7
GROUP_GAP = 18
GROUP_LABEL_H = 22
TOP = 64          # title and subtitle
FOOT = 34


def nice_step(maximum: float, ticks: int = 5) -> float:
    raw = maximum / ticks
    magnitude = 10 ** (len(str(int(raw))) - 1) if raw >= 1 else 1
    for m in (1, 2, 2.5, 5, 10):
        if raw <= m * magnitude:
            return m * magnitude
    return 10 * magnitude


def fmt(value: float, unit: str) -> str:
    if unit == 'ms':
        return f'{value:.0f} ms' if value >= 10 or value == 0 else f'{value:.1f} ms'
    if value >= 1000000:
        return f'{value / 1000000:.2f}M'.replace('.00M', 'M')
    if value >= 1000:
        return f'{value / 1000:.1f}k' if value < 100000 else f'{value / 1000:.0f}k'
    return f'{value:.0f}'


def render(chart: dict, theme: dict) -> str:
    groups = chart['groups']
    unit = chart.get('unit', '')
    maximum = max(bar['value'] for g in groups for bar in g['bars']) or 1
    step = nice_step(maximum)
    top_tick = step * -(-maximum // step)
    plot_x = LABEL_W
    plot_w = WIDTH - LABEL_W - VALUE_W

    def x_of(v: float) -> float:
        return plot_x + plot_w * v / top_tick

    rows = sum(len(g['bars']) for g in groups)
    labelled = sum(1 for g in groups if g.get('label'))
    body_h = rows * (BAR_H + BAR_GAP) + labelled * GROUP_LABEL_H + (len(groups) - 1) * GROUP_GAP
    foot_lines = chart.get('footnote', '').split('\n')
    height = TOP + body_h + 26 + FOOT + 15 * (len(foot_lines) - 1)

    out = [f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {WIDTH} {height}" width="{WIDTH}" height="{height}" '
           f'role="img" aria-label="{escape(chart["title"])}">',
           f'<style>text{{font-family:{FONT};font-variant-numeric:tabular-nums}}</style>',
           f'<text x="0" y="22" font-size="17" font-weight="600" fill="{theme["text"]}">{escape(chart["title"])}</text>',
           f'<text x="0" y="44" font-size="12.5" fill="{theme["muted"]}">{escape(chart["subtitle"])}</text>']

    plot_top = TOP
    plot_bottom = TOP + body_h
    tick = 0.0
    while tick <= top_tick + 1e-9:
        x = x_of(tick)
        out.append(f'<line x1="{x:.1f}" y1="{plot_top - 4}" x2="{x:.1f}" y2="{plot_bottom}" stroke="{theme["grid"]}" stroke-width="1"/>')
        out.append(f'<text x="{x:.1f}" y="{plot_bottom + 16}" font-size="11" text-anchor="middle" fill="{theme["muted"]}">{fmt(tick, unit)}</text>')
        tick += step
    out.append(f'<line x1="{plot_x}" y1="{plot_top - 4}" x2="{plot_x}" y2="{plot_bottom}" stroke="{theme["axis"]}" stroke-width="1"/>')

    y = plot_top
    for gi, group in enumerate(groups):
        if gi:
            y += GROUP_GAP
        if group.get('label'):
            out.append(f'<text x="0" y="{y + 15}" font-size="13" font-weight="600" fill="{theme["text"]}">{escape(group["label"])}</text>')
            y += GROUP_LABEL_H
        for bar in group['bars']:
            color = theme[bar.get('kind', 'other')]
            w = max(x_of(bar['value']) - plot_x, 0)
            weight = '600' if bar.get('kind', 'other') != 'other' else '400'
            out.append(f'<text x="{plot_x - 10}" y="{y + BAR_H / 2 + 4.5}" font-size="12.5" font-weight="{weight}" text-anchor="end" fill="{theme["text"]}">{escape(bar["label"])}</text>')
            if bar.get('failed'):
                out.append(f'<text x="{plot_x + 8}" y="{y + BAR_H / 2 + 4.5}" font-size="12" font-style="italic" fill="{theme["muted"]}">{escape(bar["failed"])}</text>')
            else:
                out.append(f'<rect x="{plot_x}" y="{y}" width="{w:.1f}" height="{BAR_H}" rx="3" fill="{color}"/>')
                label = fmt(bar['value'], unit) + (' ' + bar['note'] if bar.get('note') else '')
                out.append(f'<text x="{plot_x + w + 7:.1f}" y="{y + BAR_H / 2 + 4.5}" font-size="12" fill="{theme["text"]}">{escape(label)}</text>')
            y += BAR_H + BAR_GAP

    for i, line in enumerate(foot_lines):
        out.append(f'<text x="0" y="{height - 10 - 15 * (len(foot_lines) - 1 - i)}" font-size="11" fill="{theme["muted"]}">{escape(line)}</text>')
    out.append('</svg>')
    return '\n'.join(out) + '\n'


def main() -> None:
    with open(os.path.join(HERE, 'charts.json')) as f:
        charts = json.load(f)
    for name, chart in charts.items():
        for variant, theme in THEMES.items():
            with open(os.path.join(HERE, f'{name}-{variant}.svg'), 'w') as f:
                f.write(render(chart, theme))
        print(f'{name}: {name}-light.svg, {name}-dark.svg')


if __name__ == '__main__':
    main()
