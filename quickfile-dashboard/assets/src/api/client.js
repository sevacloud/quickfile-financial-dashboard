const { restUrl, nonce } = window.qfdData || {};

async function apiFetch( path, params = {}, method = 'GET', body = null ) {
    const url = new URL( restUrl + path );
    if ( method === 'GET' ) {
        Object.entries( params ).forEach( ( [ k, v ] ) => url.searchParams.set( k, v ) );
    }

    const options = {
        method,
        headers: {
            'X-WP-Nonce': nonce,
            'Content-Type': 'application/json',
        },
        credentials: 'same-origin',
    };

    if ( body !== null ) {
        options.body = JSON.stringify( body );
    }

    const res = await fetch( url.toString(), options );

    if ( ! res.ok ) {
        const err = await res.json().catch( () => ( { message: res.statusText } ) );
        throw new Error( err.message || `HTTP ${ res.status }` );
    }

    return res.json();
}

export const api = {
    getBalances:        ()             => apiFetch( '/balances' ),
    getMonthlySummary:  ( from, to )   => apiFetch( '/monthly-summary', { from, to } ),
    getAnnualStatement: ( from, to )   => apiFetch( '/annual-statement', { from, to } ),
    getProjects:        ( from, to )   => apiFetch( '/projects', { from, to } ),
    getUtilities:       ( from, to )   => apiFetch( '/utilities', { from, to } ),
    getYearComparison:  ( years )      => apiFetch( '/year-comparison', { years: years.join( ',' ) } ),
    getTransactions:    ( account, from, to ) => apiFetch( '/transactions', { account, from, to } ),
    getProjectBudgets:    ()                        => apiFetch( '/project-budgets' ),
    saveProjectBudget:    ( tag, budget )           => apiFetch( '/project-budgets', {}, 'POST', { tag, budget } ),
    getPriorYear:         ( fy )                    => apiFetch( '/prior-year', { fy } ),
    savePriorYearFigure:  ( fy, code, amount )      => apiFetch( '/prior-year', {}, 'POST', { fy, code, amount } ),
    getClosingBalances:   ( fy )                    => apiFetch( '/closing-balances', { fy } ),
    getBalanceSheet:      ( to )                    => apiFetch( '/balance-sheet', { to } ),
};
