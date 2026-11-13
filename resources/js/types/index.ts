export type ProductType =
    | 'access_door'
    | 'floor_door'
    | 'roof_hatch'
    | 'smoke_vent'
    | 'skylight'
    | 'ladder'
    | 'safety_rail'
    | 'valve_box'
    | 'vent_cover'
    | 'door_louver_window_kit';

export interface ProductRow {
    id: number;
    sku: string;
    model: string;
    name: string;
    url: string;
    image_url: string | null;
    product_type: ProductType;
    type_label: string;
    brand: string | null;
    size: string | null;
    fire_rated: boolean;
    is_available: boolean;
    price_cents: number | null;
    msrp_cents: number | null;
    rating: number | null;
    rating_count: number;
    tags: string[];
}

export interface PageLink {
    page: number;
    url: string;
}

export interface ProductPage {
    data: ProductRow[];
    current_page: number;
    last_page: number;
    total: number;
    from: number | null;
    to: number | null;
    prev_url: string | null;
    next_url: string | null;
    first_url: string;
    last_url: string;
    window: PageLink[];
}

export type FacetKey = 'type' | 'brand' | 'application' | 'surface_type' | 'material' | 'flange_type' | 'purpose' | 'price';

export type ProductSort = 'relevance' | 'popular' | 'price_asc' | 'price_desc' | 'size';

export interface ProductFilters {
    q: string;
    type: ProductType | '';
    width: string;
    height: string;
    fire_rated: boolean;
    insulated: boolean;
    show_unavailable: boolean;
    brand: string[];
    application: string[];
    surface_type: string[];
    material: string[];
    flange_type: string[];
    purpose: string[];
    price: string[];
    sort: ProductSort;
}

/** ProductSearchController::facets. Counts already apply every other active filter. */
export interface Facet {
    key: FacetKey;
    label: string;
    multiple: boolean;
    options: { value: string; label: string; count: number }[];
}

export interface TypeOption {
    value: ProductType;
    label: string;
    count: number;
}

/** DraftPresenter::show. Money is integer cents. */
export interface DraftOption {
    id: number | null;
    sku: string;
    name: string;
    url: string;
    image_url: string | null;
    size: string | null;
    /** Net unit price after the best discount; null = price on request. */
    price_cents: number | null;
    list_price_cents: number | null;
    discount_pct: number | null;
    discount_label: string | null;
    /** best: nothing in the notes contradicts it; also: fits the size but contradicts a detail. */
    group?: 'best' | 'also';
    checks?: FitCheck[];
    /** What sets equal matches apart ("Drywall Flange for drywall", "Store bestseller"). */
    tags?: string[];
    /** From the product page (first 8), when fetched. */
    spec_lines?: { label: string; value: string }[];
    spec_sheet_url?: string | null;
    /** The chosen product only. */
    description?: string | null;
}

/** ProductFit: one stated detail vs this product. */
export interface FitCheck {
    spec: string;
    label: string;
    value: string;
    verdict: 'yes' | 'option' | 'unknown' | 'no';
    evidence: string | null;
    option_value_id: number | null;
}

export interface AccountContact {
    name: string;
    email: string | null;
    phone: string | null;
}

/** Account::summary(). */
export interface Account {
    id: number;
    name: string;
    contacts: AccountContact[];
    ship_to: string | null;
    payment_terms: string | null;
    tax_exempt: boolean;
    discount_pct: number;
    max_discount_pct: number;
}

export type FlagSeverity = 'blocking' | 'warning' | 'info';

/** DraftFlags: computed by code, never by the AI. */
export interface DraftFlag {
    code: string;
    severity: FlagSeverity;
    message: string;
    line_id: number | null;
    quote: string | null;
    action:
        | { type: 'apply_discount'; value: number }
        | { type: 'choose_option'; value: { store_option_id: number; store_value_id: number } }
        | null;
}

export interface PriceRule {
    id: number;
    label: string;
    account: { id: number; name: string } | null;
    product_type: ProductType | null;
    brand: string | null;
    min_quantity: number;
    discount_pct: number;
    starts_on: string | null;
    ends_on: string | null;
    active: boolean;
}

export interface DraftSpec {
    key: string;
    name: string;
    value: string;
    /** Customer's words; null when the salesperson entered it. */
    quote: string | null;
}

export type LineStatus = 'ready' | 'choose' | 'needs_details' | 'needs_clarification' | 'no_match';

/** ZohoQuoteRequest::build(): exactly what goes to Zoho CRM (token masked). */
export interface ZohoRequest {
    method: string;
    url: string;
    headers: Record<string, string>;
    body: Record<string, unknown>;
}

export interface ZohoHandoff {
    mode: 'dummy' | 'live';
    request: ZohoRequest;
    response: Record<string, unknown> | null;
    record_id: string | null;
    sent_at: string | null;
    unverified_fields: string[];
}

export interface DraftLine {
    id: number;
    position: number;
    /** 1-based, as the page numbers lines (flags say "Line N"). */
    number: number;
    source: 'ai' | 'manual' | 'suggestion';
    /** Why the product was preselected, until the salesperson confirms or changes it. */
    pick: LinePick | null;
    /** Nothing preselected: how many best matches fit the notes equally. */
    shortlist_count: number | null;
    product_type: ProductType | null;
    label: string;
    quote: string | null;
    quantity: number;
    status: LineStatus;
    specs: DraftSpec[];
    size: { width: number; height: number } | null;
    fire_rated: boolean | null;
    options: DraftOption[];
    selected: DraftOption | null;
    questions: string[];
    list_price_cents: number | null;
    /** Net base price after the discount; options come on top, undiscounted. */
    base_price_cents: number | null;
    options_price_cents: number;
    /** base + options, per unit */
    unit_price_cents: number | null;
    discount_pct: number | null;
    discount_label: string | null;
    line_total_cents: number | null;
    option_groups: OptionGroup[];
    option_requests: { text: string; quote: string }[];
    unmatched_option_requests: { text: string; quote: string }[];
    options_lead_time: string | null;
    /** Qualities asked for ("airtight"): they rank products, never rule one out. */
    features: { text: string; quote: string }[];
    /** Model / brand names from the notes that we don't carry. */
    unknown_names: { spec: string; label: string; value: string; quote: string | null }[];
    /** "Usually added with this": add-ons the catalog ties to the chosen product. */
    suggestions: Suggestion[];
}

/** AccessorySuggester: an add-on for the line's product, priced for the line's quantity. */
export interface Suggestion extends DraftOption {
    reason: string;
    evidence: string | null;
    /** The notes' words, when the notes asked for it. */
    quote: string | null;
    /** catalog: compatibility links / add-on rules; ai: the optional AI suggestions. */
    source: 'catalog' | 'ai';
    /** add_on: add as its own line; variant: use instead of the chosen product. */
    kind: 'add_on' | 'variant';
}

/** The optional second AI call (DRAFT_AI_SUGGESTIONS). status null = not asked yet. */
export interface AiSuggestionsState {
    enabled: boolean;
    status: 'done' | 'failed' | null;
    model: string | null;
    error: string | null;
    at: string | null;
}

export interface OptionChoice {
    store_value_id: number | null;
    name: string;
    price_delta_cents: number | null;
    lead_time_text: string | null;
    is_default: boolean;
    is_none: boolean;
    needs_review: boolean;
    /** The store's picture of the choice (curb face, color). */
    image_url: string | null;
    /** Line numbers whose chosen color / finish this is. */
    same_as: number[];
}

/** ProductMatcher::pick: why the notes settle the preselection. */
export interface LinePick {
    confidence: 'named' | 'only' | 'best_fit';
    reason: string;
}

/** One option of the line's product ("Choose a Lock / Latch") and the current choice. */
export interface OptionGroup {
    /** Another line's color, offered here but not chosen. */
    color_match?: OptionChoice | null;
    store_option_id: number | null;
    label: string;
    required: boolean;
    choices: OptionChoice[];
    selected: {
        store_value_id: number | null;
        name: string;
        price_delta_cents: number | null;
        lead_time_text: string | null;
        source: 'default' | 'assumed' | 'notes' | 'salesperson';
        quote: string | null;
        gone: boolean;
        needs_review: boolean;
    } | null;
}

export interface Draft {
    ulid: string;
    status: 'draft' | 'finalized';
    message: string;
    /** Exactly what was sent to the AI. */
    redacted_message: string | null;
    model: string | null;
    created_at: string;
    finalized_at: string | null;
    account: Account | null;
    discount_pct: number | null;
    max_discount_pct: number;
    lines: DraftLine[];
    flags: DraftFlag[];
    totals: {
        subtotal_cents: number;
        savings_cents: number;
        ready: number;
        lines: number;
        unresolved: number;
        unpriced: number;
        blocking: number;
    };
    prices_as_of: string | null;
    can_finalize: boolean;
    can_send: boolean;
    zoho: ZohoHandoff;
    ai_suggestions: AiSuggestionsState;
}

export interface DraftSummary {
    ulid: string;
    status: 'draft' | 'finalized';
    account: string | null;
    excerpt: string;
    lines: number;
    ready: number;
    created_at: string;
}

export interface LookupProduct {
    id: number;
    sku: string;
    name: string;
    size: string | null;
    price_cents: number | null;
    image_url: string | null;
}
