/**
 * ApexCharts, loaded only when a page needs it.
 *
 * The login page and the shell carry no charts, so the library stays out of
 * the first download. Call `useApexCharts()` in a page that draws a chart.
 */

let registered = null

export function useApexCharts() {
    if (registered) return registered

    registered = Promise.all([
        import('apexcharts'),
        import('vue3-apexcharts'),
    ]).then(([apex, vueApex]) => {
        if (typeof window !== 'undefined') {
            window.ApexCharts = apex.default
        }

        return vueApex.default
    })

    return registered
}

/** Chart defaults that match the design tokens. */
export const chartTheme = {
    chart: {
        fontFamily: 'Inter, ui-sans-serif, system-ui, sans-serif',
        toolbar: { show: false },
        zoom: { enabled: false },
        animations: {
            enabled: true,
            speed: 420,
            easing: 'easeout',
        },
    },
    grid: {
        borderColor: 'oklch(0.92 0.005 286)',
        strokeDashArray: 4,
        padding: { left: 12, right: 12 },
    },
    dataLabels: { enabled: false },
    legend: {
        position: 'top',
        horizontalAlign: 'right',
        fontSize: '12px',
        markers: { size: 5 },
    },
    tooltip: {
        theme: 'light',
        style: { fontSize: '12px' },
    },
    colors: [
        'oklch(0.55 0.2 286)',
        'oklch(0.68 0.14 162)',
        'oklch(0.78 0.15 85)',
        'oklch(0.65 0.16 235)',
        'oklch(0.62 0.21 25)',
    ],
}

/** The muted grey the axes are labelled in. */
const axisLabel = { style: { colors: 'oklch(0.66 0.013 286)', fontSize: '11px' } }

/**
 * Sales against purchases, over time.
 *
 * The dashboard and the reports both draw this, so it is written once. Two
 * copies would drift apart the first time somebody adjusted a colour, and
 * the client would be looking at the same figures in two different shapes.
 *
 * @param {{categories: string[]}} trend  From InventoryMetrics.
 * @param {(value: number) => string} money  The currency formatter.
 */
export function trendChartOptions(trend, money) {
    if (!trend) return null

    return {
        ...chartTheme,
        chart: {
            ...chartTheme.chart,
            type: 'line',
            animations: {
                enabled: true,
                speed: 700,
                easing: 'easeinout',
                animateGradually: { enabled: true, delay: 120 },
            },
        },
        stroke: { curve: 'smooth', width: 2.5 },
        markers: { size: 4, strokeWidth: 0, hover: { size: 6 } },
        xaxis: {
            categories: trend.categories,
            axisBorder: { show: false },
            axisTicks: { show: false },
            labels: axisLabel,
        },
        yaxis: {
            labels: {
                ...axisLabel,
                formatter: (value) =>
                    new Intl.NumberFormat('en-PH', { notation: 'compact' }).format(value ?? 0),
            },
        },
        tooltip: {
            y: { formatter: (value) => money(value ?? 0) },
        },
        legend: { position: 'top', horizontalAlign: 'right' },
    }
}

/**
 * A share of the whole, as a ring.
 *
 * The legend carries the money and the slice carries the share, so the
 * reader is not asked to work either one out.
 *
 * @param {{labels: string[], series: number[]}} data
 * @param {(value: number) => string} money
 */
export function donutChartOptions(data, money) {
    if (!data) return null

    return {
        ...chartTheme,
        chart: { ...chartTheme.chart, type: 'donut' },
        labels: data.labels,
        stroke: { width: 0 },
        dataLabels: {
            enabled: true,
            formatter: (share) => `${Number(share).toFixed(1)}%`,
            style: { fontSize: '11px', fontWeight: 600 },
            dropShadow: { enabled: false },
        },
        legend: {
            position: 'bottom',
            horizontalAlign: 'center',
            fontSize: '12px',
            markers: { radius: 4 },
            formatter: (label, { seriesIndex, w }) =>
                `${label} — ${money(w.globals.series[seriesIndex] ?? 0)}`,
        },
        plotOptions: {
            pie: {
                donut: {
                    size: '62%',
                    labels: { show: false },
                },
            },
        },
        tooltip: { y: { formatter: (value) => money(value ?? 0) } },
    }
}

/**
 * An axis from 0 to a round number some way past the longest bar, so a
 * value written after that bar is not cut off at the chart's edge.
 */
function roomForValues(series) {
    const longest = Math.max(0, ...(series ?? []).map(Number).filter(Number.isFinite))

    if (longest <= 0) return {}

    const step = 10 ** Math.floor(Math.log10(longest)) / 2

    return { min: 0, max: Math.ceil((longest * 1.15) / step) * step }
}

/**
 * Side-by-side bars.
 *
 * Horizontal, because a category name reads better along a bar than
 * sideways under one.
 *
 * @param {{labels: string[]}} data
 * @param {(value: number) => string} format
 */
export function barChartOptions(data, format, { stacked = false, labelWidth = null, showValues = false } = {}) {
    if (!data) return null

    return {
        ...chartTheme,
        chart: { ...chartTheme.chart, type: 'bar', stacked },
        plotOptions: {
            bar: {
                horizontal: true,
                borderRadius: 4,
                barHeight: '62%',
                // A value written at the end of its bar, when asked for.
                dataLabels: { position: 'top' },
            },
        },
        dataLabels: showValues
            ? {
                  enabled: true,
                  offsetX: 8,
                  textAnchor: 'start',
                  formatter: (value) => format(value ?? 0),
                  style: { fontSize: '11px', fontWeight: 600, colors: ['oklch(0.45 0.02 286)'] },
              }
            : { enabled: false },
        xaxis: {
            categories: data.labels,
            // Room past the longest bar for the value written after it.
            ...(showValues ? roomForValues(data.series) : {}),
            axisBorder: { show: false },
            axisTicks: { show: false },
            labels: {
                ...axisLabel,
                formatter: (value) =>
                    new Intl.NumberFormat('en-PH', { notation: 'compact' }).format(value ?? 0),
            },
        },
        // A longer label column when the chart has the room for one, so a
        // category name is not cut short.
        yaxis: { labels: labelWidth ? { ...axisLabel, maxWidth: labelWidth } : axisLabel },
        tooltip: { y: { formatter: (value) => format(value ?? 0) } },
        legend: { position: 'top', horizontalAlign: 'right' },
        // On a phone the bars need the width more than the names and the
        // values do: a shorter name column, no value after each bar (a tap
        // still shows it) and fewer numbers along the axis.
        ...(labelWidth || showValues
            ? {
                  responsive: [
                      {
                          breakpoint: 640,
                          options: {
                              dataLabels: { enabled: false },
                              yaxis: { labels: { ...axisLabel, maxWidth: 110 } },
                              xaxis: { tickAmount: 3 },
                          },
                      },
                  ],
              }
            : {}),
    }
}
