export const formatCurrency = (amount) => 'Nu. ' + Number(amount || 0).toLocaleString('en-US')

export const formatDate = (date) => {
    if (!date) return '—'
    return new Date(date).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    })
}

export const formatDateTime = (date) => {
    if (!date) return '—'
    return new Date(date).toLocaleString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    })
}

const STATUS_VARIANTS = {
    pending: 'warning',
    approved: 'success',
    rejected: 'destructive',
}

export const getStatusVariant = (status) => STATUS_VARIANTS[status] ?? 'secondary'

const BANK_LABELS = {
    bob: 'Bank of Bhutan',
    bnb: 'Bhutan National Bank',
    dpnb: 'Druk PNB Ltd',
    bdbl: 'Bhutan Development Bank Ltd.',
    tbank: 'T Bank Ltd',
    dk: 'Dk.',
    bank_transfer: 'Bank Transfer',
    upi: 'UPI',
    card: 'Card',
    cash: 'Cash',
    other: 'Other',
}

export const getBankLabel = (bank) => BANK_LABELS[bank] ?? bank
